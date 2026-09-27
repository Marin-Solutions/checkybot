package run

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"math/big"
	"net"
	"net/http"
	"sync/atomic"
	"testing"
	"time"

	"github.com/Marin-Solutions/checkybot/checker/internal/status"
	"github.com/Marin-Solutions/checkybot/checker/internal/store"
	"github.com/Marin-Solutions/checkybot/checker/internal/testdb"
)

func TestUptimeHTTPOutcomesMatchLaravel(t *testing.T) {
	db := testdb.Open(t)
	testdb.Live(t, db)
	runner := testRunner(db)

	ok := serve(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("User-Agent") != "GuzzleHttp/7" {
			t.Errorf("user agent = %s", r.Header.Get("User-Agent"))
		}
		w.WriteHeader(http.StatusOK)
	})
	missing := serve(t, func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(http.StatusNotFound) })
	broken := serve(t, func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(http.StatusBadGateway) })
	loop := serve(t, func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, "/", http.StatusFound)
	})
	closed, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	refused := "http://" + closed.Addr().String()
	closed.Close()
	var hits atomic.Int32
	flaky := serve(t, func(w http.ResponseWriter, r *http.Request) {
		if hits.Add(1) == 1 {
			w.WriteHeader(http.StatusBadGateway)
			return
		}
		w.WriteHeader(http.StatusOK)
	})

	cases := []struct {
		url    string
		status string
		code   int
	}{
		{ok, status.Healthy, 200},
		{missing, status.Warning, 404},
		{broken, status.Danger, 502},
		{loop, status.Danger, 0},
		{refused, status.Danger, 0},
		{flaky, status.Healthy, 200},
	}
	ctx := context.Background()
	for _, tc := range cases {
		id := testdb.MustInsert(t, db, `INSERT INTO websites (url, uptime_check, uptime_interval, created_at) VALUES (?, 1, 1, UTC_TIMESTAMP())`, tc.url)
		if err := runner.CheckUptime(ctx, id, false); err != nil {
			t.Fatal(err)
		}
		gotStatus, gotCode := history(t, db, id)
		if gotStatus != tc.status || gotCode != tc.code {
			t.Fatalf("%s status=%s code=%d, want %s/%d", tc.url, gotStatus, gotCode, tc.status, tc.code)
		}
	}
	if hits.Load() != 2 {
		t.Fatalf("retries = %d, want 2", hits.Load())
	}
}

func TestParallelUptimeDoesNotWriteTwoRows(t *testing.T) {
	db := testdb.Open(t)
	testdb.Live(t, db)
	started := make(chan struct{})
	release := make(chan struct{})
	url := serve(t, func(w http.ResponseWriter, r *http.Request) {
		select {
		case <-started:
		default:
			close(started)
		}
		<-release
		w.WriteHeader(http.StatusOK)
	})
	id := testdb.MustInsert(t, db, `INSERT INTO websites (url, uptime_check, uptime_interval, created_at) VALUES (?, 1, 1, UTC_TIMESTAMP())`, url)
	runner := testRunner(db)
	ctx := context.Background()
	done := make(chan error, 2)
	go func() { done <- runner.CheckUptime(ctx, id, false) }()
	select {
	case <-started:
	case <-time.After(3 * time.Second):
		t.Fatal("first check did not reach the server")
	}
	go func() { done <- runner.CheckUptime(ctx, id, false) }()
	time.Sleep(200 * time.Millisecond)
	close(release)
	for i := 0; i < 2; i++ {
		if err := <-done; err != nil {
			t.Fatal(err)
		}
	}
	var n int
	if err := db.DB.QueryRow(`SELECT COUNT(*) FROM website_log_history WHERE website_id = ?`, id).Scan(&n); err != nil {
		t.Fatal(err)
	}
	if n != 1 {
		t.Fatalf("history rows = %d, want 1", n)
	}
}

func TestStatusChangeWritesAnOutboxRow(t *testing.T) {
	db := testdb.Open(t)
	testdb.Live(t, db)
	url := serve(t, func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(http.StatusBadGateway) })
	id := testdb.MustInsert(t, db, `INSERT INTO websites (url, uptime_check, uptime_interval, current_status, created_at) VALUES (?, 1, 1, 'healthy', UTC_TIMESTAMP())`, url)
	if err := testRunner(db).CheckUptime(context.Background(), id, false); err != nil {
		t.Fatal(err)
	}
	var kind, event, state string
	if err := db.DB.QueryRow(`SELECT kind, event, status FROM checker_outbox WHERE subject_id = ?`, id).Scan(&kind, &event, &state); err != nil {
		t.Fatal(err)
	}
	if kind != "website_transition" || event != "heartbeat" || state != status.Danger {
		t.Fatalf("outbox %s %s %s", kind, event, state)
	}
}

func TestKeywordMissIsWarningAndSecretHeaderIsMasked(t *testing.T) {
	db := testdb.Open(t)
	testdb.Live(t, db)
	url := serve(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "secret" {
			t.Errorf("authorization was not decrypted for the request")
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"message":"nope"}`))
	})
	id := testdb.MustInsert(t, db, `
		INSERT INTO monitor_apis (url, http_method, headers, is_enabled, expected_status, package_interval, save_failed_response, created_at)
		VALUES (?, 'GET', ?, 1, 200, '1m', 1, UTC_TIMESTAMP())
	`, url, `{"Authorization":"secret","Accept":"application/json"}`)
	testdb.MustInsert(t, db, `
		INSERT INTO monitor_api_assertions (monitor_api_id, data_path, assertion_type, comparison_operator, expected_value, is_active, sort_order)
		VALUES (?, 'message', 'value_compare', 'contains', 'ok', 1, 1)
	`, id)
	if err := testRunner(db).CheckAPI(context.Background(), id, false); err != nil {
		t.Fatal(err)
	}
	var state, summary, headers string
	var code int
	if err := db.DB.QueryRow(`
		SELECT status, summary, http_code, request_headers FROM monitor_api_results WHERE monitor_api_id = ?
	`, id).Scan(&state, &summary, &code, &headers); err != nil {
		t.Fatal(err)
	}
	if state != status.Warning || code != 200 {
		t.Fatalf("status=%s code=%d summary=%s", state, code, summary)
	}
	if !json.Valid([]byte(headers)) || !contains(headers, "[redacted]") || contains(headers, "secret") {
		t.Fatalf("headers stored as %s", headers)
	}
	var current string
	if err := db.DB.QueryRow(`SELECT current_status FROM monitor_apis WHERE id = ?`, id).Scan(&current); err != nil {
		t.Fatal(err)
	}
	if current != status.Warning {
		t.Fatalf("monitor status = %s", current)
	}
}

func TestAPI500IsDangerAndTimeoutIsTransport(t *testing.T) {
	db := testdb.Open(t)
	testdb.Live(t, db)
	broken := serve(t, func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(http.StatusInternalServerError) })
	hung := serve(t, func(w http.ResponseWriter, r *http.Request) { time.Sleep(2 * time.Second) })
	ctx := context.Background()
	runner := testRunner(db)
	down := testdb.MustInsert(t, db, `INSERT INTO monitor_apis (url, is_enabled, expected_status, timeout_seconds, retry_count, package_interval, created_at) VALUES (?, 1, 200, 5, 1, '1m', UTC_TIMESTAMP())`, broken)
	if err := runner.CheckAPI(ctx, down, false); err != nil {
		t.Fatal(err)
	}
	if state, _ := apiResult(t, db, down); state != status.Danger {
		t.Fatalf("5xx status = %s", state)
	}
	slow := testdb.MustInsert(t, db, `INSERT INTO monitor_apis (url, is_enabled, timeout_seconds, retry_count, package_interval, created_at) VALUES (?, 1, 1, 1, '1m', UTC_TIMESTAMP())`, hung)
	if err := runner.CheckAPI(ctx, slow, false); err != nil {
		t.Fatal(err)
	}
	state, transport := apiResult(t, db, slow)
	if state != status.Danger || transport != "timeout" {
		t.Fatalf("timeout status=%s transport=%s", state, transport)
	}
}

func TestTLSCases(t *testing.T) {
	db := testdb.Open(t)
	testdb.Live(t, db)
	runner := testRunner(db)
	ctx := context.Background()

	validURL, validPool := tlsServer(t, "127.0.0.1", time.Now().Add(40*24*time.Hour), func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	})
	soonURL, soonPool := tlsServer(t, "127.0.0.1", time.Now().Add(10*24*time.Hour), func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	})
	expiredURL, expiredPool := tlsServer(t, "127.0.0.1", time.Now().Add(-2*time.Hour), func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	})
	wrongURL, wrongPool := tlsServer(t, "wrong.example", time.Now().Add(40*24*time.Hour), func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	})

	assertSite := func(rawURL string, pool *x509.CertPool, want string, summaryPart string) {
		t.Helper()
		local := *runner
		local.Roots = pool
		id := testdb.MustInsert(t, db, `INSERT INTO websites (url, uptime_check, ssl_check, uptime_interval, created_at) VALUES (?, 1, 1, 1, UTC_TIMESTAMP())`, rawURL)
		if err := local.CheckUptime(ctx, id, false); err != nil {
			t.Fatal(err)
		}
		var state, summary string
		if err := db.DB.QueryRow(`SELECT status, summary FROM website_log_history WHERE website_id = ?`, id).Scan(&state, &summary); err != nil {
			t.Fatal(err)
		}
		if state != want || !contains(summary, summaryPart) {
			t.Fatalf("status=%s summary=%s, want %s containing %q", state, summary, want, summaryPart)
		}
	}
	assertSite(validURL, validPool, status.Healthy, "succeeded with HTTP status 200")
	assertSite(soonURL, soonPool, status.Warning, "expires in")
	assertSite(expiredURL, expiredPool, status.Danger, "before an expiry date could be read")
	assertSite(wrongURL, wrongPool, status.Danger, "before an expiry date could be read")
}

func TestSSLOnlyCheckRecordsTheCertificateAndAReminder(t *testing.T) {
	db := testdb.Open(t)
	testdb.Live(t, db)
	now := time.Now().UTC()
	expiry := time.Date(now.Year(), now.Month(), now.Day(), 18, 0, 0, 0, time.UTC).Add(14 * 24 * time.Hour)
	rawURL, pool := tlsServer(t, "127.0.0.1", expiry, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	})
	id := testdb.MustInsert(t, db, `
		INSERT INTO websites (url, uptime_check, ssl_check, uptime_interval, created_at)
		VALUES (?, 0, 1, 1, UTC_TIMESTAMP())
	`, rawURL)
	runner := testRunner(db)
	runner.Roots = pool
	if err := runner.CheckSSL(context.Background(), id); err != nil {
		t.Fatal(err)
	}
	var state, summary string
	if err := db.DB.QueryRow(`SELECT status, summary FROM website_log_history WHERE website_id = ?`, id).Scan(&state, &summary); err != nil {
		t.Fatal(err)
	}
	if state != status.Warning || !contains(summary, "expires in") {
		t.Fatalf("ssl-only status=%s summary=%s", state, summary)
	}
	var reminders int
	if err := db.DB.QueryRow(`SELECT COUNT(*) FROM checker_outbox WHERE subject_id = ? AND kind = 'ssl_reminder'`, id).Scan(&reminders); err != nil {
		t.Fatal(err)
	}
	if reminders != 1 {
		t.Fatalf("reminders = %d", reminders)
	}
}

func TestShadowComparesWithoutWritingHistory(t *testing.T) {
	db := testdb.Open(t)
	url := serve(t, func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(http.StatusOK) })
	id := testdb.MustInsert(t, db, `INSERT INTO websites (url, uptime_check, uptime_interval, created_at) VALUES (?, 1, 1, UTC_TIMESTAMP())`, url)
	if _, err := db.DB.Exec(`
		INSERT INTO website_log_history (website_id, http_status_code, status, summary, run_source, is_on_demand, created_at, updated_at)
		VALUES (?, 500, 'danger', 'old', 'scheduled', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())
	`, id); err != nil {
		t.Fatal(err)
	}
	if _, err := db.DB.Exec(`UPDATE checker_settings SET setting_value = 'shadow' WHERE setting_key = 'process_mode'`); err != nil {
		t.Fatal(err)
	}
	if err := testRunner(db).Tick(context.Background()); err != nil {
		t.Fatal(err)
	}
	var n int
	if err := db.DB.QueryRow(`SELECT COUNT(*) FROM website_log_history WHERE website_id = ?`, id).Scan(&n); err != nil {
		t.Fatal(err)
	}
	if n != 1 {
		t.Fatalf("shadow wrote history, rows=%d", n)
	}
	var result, goStatus string
	if err := db.DB.QueryRow(`SELECT match_result, go_status FROM checker_shadow_samples WHERE kind = 'uptime' AND subject_id = ?`, id).Scan(&result, &goStatus); err != nil {
		t.Fatal(err)
	}
	if result != "mismatch" || goStatus != status.Healthy {
		t.Fatalf("shadow %s/%s", result, goStatus)
	}
}

func testRunner(db *store.Store) *Runner {
	return &Runner{Store: db, Location: time.UTC, Concurrency: 4, RetryDelay: 0, Log: nil}
}

func history(t *testing.T, db *store.Store, id int64) (string, int) {
	t.Helper()
	var state string
	var code int
	if err := db.DB.QueryRow(`SELECT status, http_status_code FROM website_log_history WHERE website_id = ?`, id).Scan(&state, &code); err != nil {
		t.Fatal(err)
	}
	return state, code
}

func apiResult(t *testing.T, db *store.Store, id int64) (string, string) {
	t.Helper()
	var state string
	var transport *string
	if err := db.DB.QueryRow(`SELECT status, transport_error_type FROM monitor_api_results WHERE monitor_api_id = ?`, id).Scan(&state, &transport); err != nil {
		t.Fatal(err)
	}
	if transport == nil {
		return state, ""
	}
	return state, *transport
}

func contains(value, part string) bool {
	return len(part) == 0 || (len(value) >= len(part) && (value == part || len(value) > 0 && (stringIndex(value, part) >= 0)))
}

func stringIndex(value, part string) int {
	for i := 0; i+len(part) <= len(value); i++ {
		if value[i:i+len(part)] == part {
			return i
		}
	}
	return -1
}

func serve(t *testing.T, handler http.HandlerFunc) string {
	t.Helper()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	srv := &http.Server{Handler: handler}
	go srv.Serve(ln)
	t.Cleanup(func() {
		ctx, cancel := context.WithTimeout(context.Background(), time.Second)
		defer cancel()
		_ = srv.Shutdown(ctx)
	})
	return "http://" + ln.Addr().String()
}

func tlsServer(t *testing.T, name string, notAfter time.Time, handler http.HandlerFunc) (string, *x509.CertPool) {
	t.Helper()
	cert, pool := testCert(t, name, notAfter)
	ln, err := tls.Listen("tcp", "127.0.0.1:0", &tls.Config{Certificates: []tls.Certificate{cert}})
	if err != nil {
		t.Fatal(err)
	}
	srv := &http.Server{Handler: handler}
	go srv.Serve(ln)
	t.Cleanup(func() {
		ctx, cancel := context.WithTimeout(context.Background(), time.Second)
		defer cancel()
		_ = srv.Shutdown(ctx)
	})
	return "https://" + ln.Addr().String(), pool
}

func testCert(t *testing.T, name string, notAfter time.Time) (tls.Certificate, *x509.CertPool) {
	t.Helper()
	caKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	caTpl := &x509.Certificate{
		SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "checky test"},
		NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(48 * time.Hour),
		IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign,
	}
	caDER, err := x509.CreateCertificate(rand.Reader, caTpl, caTpl, &caKey.PublicKey, caKey)
	if err != nil {
		t.Fatal(err)
	}
	ca, err := x509.ParseCertificate(caDER)
	if err != nil {
		t.Fatal(err)
	}
	leafKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	notBefore := time.Now().Add(-time.Hour)
	if notAfter.Before(notBefore) {
		notBefore = notAfter.Add(-time.Hour)
	}
	leaf := &x509.Certificate{
		SerialNumber: big.NewInt(2), Subject: pkix.Name{CommonName: name},
		NotBefore: notBefore, NotAfter: notAfter,
		KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}
	if ip := net.ParseIP(name); ip != nil {
		leaf.IPAddresses = []net.IP{ip}
	} else {
		leaf.DNSNames = []string{name}
	}
	leafDER, err := x509.CreateCertificate(rand.Reader, leaf, ca, &leafKey.PublicKey, caKey)
	if err != nil {
		t.Fatal(err)
	}
	pool := x509.NewCertPool()
	pool.AddCert(ca)
	return tls.Certificate{Certificate: [][]byte{leafDER, caDER}, PrivateKey: leafKey}, pool
}
