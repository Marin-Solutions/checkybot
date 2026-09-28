// Package probe performs the HTTP and TLS calls the Laravel jobs perform.
package probe

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"
)

const (
	maxBody       = 2 << 20
	guzzleUA      = "GuzzleHttp/7"
	maxRedirects  = 5
	redirectError = "stopped after 5 redirects"
)

// Outcome is one finished attempt series. Transport is set only when no HTTP status exists.
type Outcome struct {
	Code      int
	Body      []byte
	Headers   http.Header
	Duration  time.Duration
	Attempts  int
	Transport string
	ErrorText string
	ErrorCode *int
}

// Request is one uptime or API call.
type Request struct {
	Method         string
	URL            string
	Headers        map[string]string
	Body           []byte
	ContentType    string
	Timeout        time.Duration
	ConnectTimeout time.Duration
	Attempts       int
	RetryDelay     time.Duration
	Insecure       bool
	Roots          *x509.CertPool
}

// Do follows Laravel Http::retry: Attempts is the total number of tries.
// Non-2xx responses are retried. The last HTTP status is kept. Transport
// errors are retried and, on the last try, returned as code 0.
func Do(ctx context.Context, req Request) Outcome {
	if req.Attempts < 1 {
		req.Attempts = 1
	}
	if req.Method == "" {
		req.Method = http.MethodGet
	}
	if req.Timeout <= 0 {
		req.Timeout = 10 * time.Second
	}
	client := newClient(req)
	start := time.Now()
	var last Outcome
	for attempt := 1; attempt <= req.Attempts; attempt++ {
		last = once(ctx, client, req)
		last.Attempts = attempt
		last.Duration = time.Since(start)
		retryable := last.Transport != "" || last.Code < 200 || last.Code >= 300
		if !retryable || attempt == req.Attempts {
			break
		}
		timer := time.NewTimer(req.RetryDelay)
		select {
		case <-ctx.Done():
			timer.Stop()
			last.Transport = "timeout"
			last.ErrorText = "request canceled"
			last.Code = 0
			last.Duration = time.Since(start)
			return last
		case <-timer.C:
		}
	}
	last.Duration = time.Since(start)
	return last
}

func once(ctx context.Context, client *http.Client, req Request) Outcome {
	attemptCtx, cancel := context.WithTimeout(ctx, req.Timeout)
	defer cancel()
	httpReq, err := http.NewRequestWithContext(attemptCtx, req.Method, req.URL, bytes.NewReader(req.Body))
	if err != nil {
		return transportOutcome(err)
	}
	for key, value := range req.Headers {
		// net/http ignores Header["Host"]; the override must go on Request.Host,
		// as Guzzle honoured it, so direct-IP origin checks reach the right vhost.
		if strings.EqualFold(key, "Host") {
			httpReq.Host = value
			continue
		}
		httpReq.Header.Set(key, value)
	}
	if httpReq.Header.Get("User-Agent") == "" {
		httpReq.Header.Set("User-Agent", guzzleUA)
	}
	if req.ContentType != "" && httpReq.Header.Get("Content-Type") == "" {
		httpReq.Header.Set("Content-Type", req.ContentType)
	}
	res, err := client.Do(httpReq)
	if err != nil {
		return transportOutcome(err)
	}
	defer res.Body.Close()
	body, err := io.ReadAll(io.LimitReader(res.Body, maxBody))
	if err != nil {
		return transportOutcome(err)
	}
	return Outcome{Code: res.StatusCode, Body: body, Headers: res.Header.Clone()}
}

func newClient(req Request) *http.Client {
	connect := req.ConnectTimeout
	if connect <= 0 {
		connect = req.Timeout
	}
	transport := &http.Transport{
		Proxy: http.ProxyFromEnvironment,
		DialContext: (&net.Dialer{
			Timeout:   connect,
			KeepAlive: 30 * time.Second,
		}).DialContext,
		TLSHandshakeTimeout: connect,
		TLSClientConfig: &tls.Config{
			MinVersion:         tls.VersionTLS12,
			InsecureSkipVerify: req.Insecure, // uptime matches Laravel withoutVerifying
			RootCAs:            req.Roots,
		},
		ForceAttemptHTTP2:     false,
		MaxIdleConns:          10,
		IdleConnTimeout:       30 * time.Second,
		ExpectContinueTimeout: time.Second,
	}
	return &http.Client{
		Timeout:   req.Timeout,
		Transport: transport,
		CheckRedirect: func(request *http.Request, via []*http.Request) error {
			if len(via) >= maxRedirects {
				return fmt.Errorf("%s", redirectError)
			}
			return nil
		},
	}
}

func transportOutcome(err error) Outcome {
	text := err.Error()
	kind := classify(text)
	return Outcome{Code: 0, Transport: kind, ErrorText: trim(text, 1000)}
}

func classify(message string) string {
	normalized := strings.ToLower(message)
	switch {
	case strings.Contains(normalized, "no such host") ||
		strings.Contains(normalized, "could not resolve") ||
		strings.Contains(normalized, "name or service not known") ||
		strings.Contains(normalized, "server misbehaving"):
		return "dns"
	case strings.Contains(normalized, "timed out") ||
		strings.Contains(normalized, "timeout") ||
		strings.Contains(normalized, "deadline exceeded") ||
		strings.Contains(normalized, "i/o timeout"):
		return "timeout"
	case strings.Contains(normalized, "ssl") ||
		strings.Contains(normalized, "tls") ||
		strings.Contains(normalized, "certificate") ||
		strings.Contains(normalized, "x509") ||
		strings.Contains(normalized, "handshake"):
		return "tls"
	case strings.Contains(normalized, "connection refused") ||
		strings.Contains(normalized, "connection reset") ||
		strings.Contains(normalized, "network is unreachable") ||
		strings.Contains(normalized, "no route to host") ||
		strings.Contains(normalized, "empty reply"):
		return "connection"
	default:
		return "unknown"
	}
}

func trim(value string, max int) string {
	runes := []rune(value)
	if len(runes) <= max {
		return value
	}
	if max <= 3 {
		return string(runes[:max])
	}
	return string(runes[:max-3]) + "..."
}

// Certificate is the TLS expiry read the way Spatie does: verify the chain and the name.
// A failed handshake returns a nil time. roots may be nil (system pool).
func Certificate(ctx context.Context, rawURL string, roots *x509.CertPool) (*time.Time, error) {
	host, port, err := hostPort(rawURL)
	if err != nil || host == "" {
		return nil, err
	}
	dialer := &tls.Dialer{
		NetDialer: &net.Dialer{Timeout: 30 * time.Second},
		Config: &tls.Config{
			ServerName: host,
			MinVersion: tls.VersionTLS12,
			RootCAs:    roots,
		},
	}
	conn, err := dialer.DialContext(ctx, "tcp", net.JoinHostPort(host, port))
	if err != nil {
		return nil, err
	}
	defer conn.Close()
	tlsConn, ok := conn.(*tls.Conn)
	if !ok {
		return nil, fmt.Errorf("tls connection was not established")
	}
	state := tlsConn.ConnectionState()
	if len(state.PeerCertificates) == 0 {
		return nil, fmt.Errorf("no peer certificate")
	}
	expiry := state.PeerCertificates[0].NotAfter.UTC()
	return &expiry, nil
}

func hostPort(rawURL string) (string, string, error) {
	candidate := strings.TrimSpace(rawURL)
	if candidate == "" {
		return "", "", fmt.Errorf("empty url")
	}
	if !strings.Contains(candidate, "://") {
		candidate = "https://" + candidate
	}
	parsed, err := url.Parse(candidate)
	if err != nil {
		return "", "", err
	}
	host := parsed.Hostname()
	port := parsed.Port()
	if port == "" {
		port = "443"
	}
	return host, port, nil
}
