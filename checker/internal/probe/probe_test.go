package probe

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// originFixture behaves like an nginx origin reached by IP: only the
// scrappa.co vhost serves the readiness endpoint; the default vhost
// answers with nginx's HTML 404.
func originFixture(t *testing.T, readyStatus int) *httptest.Server {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Host != "scrappa.co" {
			w.Header().Set("Content-Type", "text/html")
			w.WriteHeader(http.StatusNotFound)
			_, _ = w.Write([]byte("<html><center><h1>404 Not Found</h1></center></html>"))
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(readyStatus)
		if readyStatus == http.StatusOK {
			_, _ = w.Write([]byte(`{"status":"ok","app":true}`))
			return
		}
		_, _ = w.Write([]byte(`{"status":"not_ready","app":false}`))
	}))
	t.Cleanup(server.Close)
	return server
}

func readiness(url string, headers map[string]string) Request {
	return Request{URL: url + "/health/ready", Headers: headers, Timeout: 5 * time.Second, Attempts: 1}
}

func TestHostHeaderOverrideReachesTheNamedVhost(t *testing.T) {
	for _, key := range []string{"Host", "host"} {
		server := originFixture(t, http.StatusOK)
		outcome := Do(context.Background(), readiness(server.URL, map[string]string{key: "scrappa.co", "Accept": "application/json"}))
		if outcome.Transport != "" || outcome.Code != http.StatusOK || string(outcome.Body) != `{"status":"ok","app":true}` {
			t.Fatalf("%s override: got code=%d transport=%q error=%q body=%s", key, outcome.Code, outcome.Transport, outcome.ErrorText, outcome.Body)
		}
	}
}

func TestHostHeaderOverrideStillReportsAFailingOrigin(t *testing.T) {
	server := originFixture(t, http.StatusServiceUnavailable)
	outcome := Do(context.Background(), readiness(server.URL, map[string]string{"Host": "scrappa.co"}))
	if outcome.Transport != "" || outcome.Code != http.StatusServiceUnavailable || string(outcome.Body) != `{"status":"not_ready","app":false}` {
		t.Fatalf("got code=%d transport=%q error=%q body=%s", outcome.Code, outcome.Transport, outcome.ErrorText, outcome.Body)
	}
}

func TestWithoutHostOverrideTheURLHostIsUsed(t *testing.T) {
	server := originFixture(t, http.StatusOK)
	outcome := Do(context.Background(), readiness(server.URL, nil))
	if outcome.Transport != "" || outcome.Code != http.StatusNotFound {
		t.Fatalf("expected the default vhost 404, got code=%d transport=%q error=%q", outcome.Code, outcome.Transport, outcome.ErrorText)
	}
}
