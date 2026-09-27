// Package run schedules uptime, SSL, and API checks and keeps Laravel as the notifier.
package run

import (
	"context"
	"crypto/x509"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"math"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/Marin-Solutions/checkybot/checker/internal/assert"
	"github.com/Marin-Solutions/checkybot/checker/internal/crypt"
	"github.com/Marin-Solutions/checkybot/checker/internal/probe"
	"github.com/Marin-Solutions/checkybot/checker/internal/status"
	"github.com/Marin-Solutions/checkybot/checker/internal/store"
)

// Runner executes due checks. Shadow mode never writes live rows or takes the shared lock.
type Runner struct {
	Store       *store.Store
	Location    *time.Location
	Key         crypt.Key
	HasKey      bool
	Concurrency int
	RetryDelay  time.Duration
	Roots       *x509.CertPool
	Now         func() time.Time
	Log         *slog.Logger
}

func (r *Runner) now() time.Time {
	if r.Now != nil {
		return r.Now()
	}
	return time.Now()
}

func (r *Runner) loc() *time.Location {
	if r.Location != nil {
		return r.Location
	}
	return time.UTC
}

func (r *Runner) log() *slog.Logger {
	if r.Log != nil {
		return r.Log
	}
	return slog.Default()
}

func (r *Runner) limit() int {
	if r.Concurrency < 1 {
		return 8
	}
	return r.Concurrency
}

// Tick runs one scheduler pass from the current checker_settings.
func (r *Runner) Tick(ctx context.Context) error {
	settings, err := r.Store.Settings(ctx)
	if err != nil {
		return err
	}
	switch settings["process_mode"] {
	case "shadow":
		return errors.Join(r.shadowUptime(ctx), r.shadowSSL(ctx), r.shadowAPI(ctx))
	case "live":
		var errs []error
		if settings["uptime_owner"] == "go" {
			errs = append(errs, r.liveUptime(ctx))
		}
		if settings["ssl_owner"] == "go" {
			errs = append(errs, r.liveSSL(ctx))
		}
		if settings["api_owner"] == "go" {
			errs = append(errs, r.liveAPI(ctx))
		}
		return errors.Join(errs...)
	default:
		return nil
	}
}

func (r *Runner) liveUptime(ctx context.Context) error {
	now := r.now()
	due, err := r.Store.DueUptime(ctx, now)
	if err != nil {
		return err
	}
	diag, err := r.Store.DiagnosticWebsites(ctx)
	if err != nil {
		return err
	}
	return errors.Join(
		r.parallel(ctx, due, func(ctx context.Context, id int64) error { return r.CheckUptime(ctx, id, false) }),
		r.parallel(ctx, diag, func(ctx context.Context, id int64) error { return r.CheckUptime(ctx, id, true) }),
	)
}

func (r *Runner) liveSSL(ctx context.Context) error {
	ids, err := r.Store.DueSSL(ctx, r.now())
	if err != nil {
		return err
	}
	return r.parallel(ctx, ids, func(ctx context.Context, id int64) error { return r.CheckSSL(ctx, id) })
}

func (r *Runner) liveAPI(ctx context.Context) error {
	now := r.now()
	due, err := r.Store.DueAPI(ctx, now)
	if err != nil {
		return err
	}
	diag, err := r.Store.DiagnosticAPIs(ctx)
	if err != nil {
		return err
	}
	return errors.Join(
		r.parallel(ctx, due, func(ctx context.Context, id int64) error { return r.CheckAPI(ctx, id, false) }),
		r.parallel(ctx, diag, func(ctx context.Context, id int64) error { return r.CheckAPI(ctx, id, true) }),
	)
}

func (r *Runner) parallel(ctx context.Context, ids []int64, fn func(context.Context, int64) error) error {
	sem := make(chan struct{}, r.limit())
	var wg sync.WaitGroup
	errCh := make(chan error, len(ids))
	for _, id := range ids {
		if ctx.Err() != nil {
			break
		}
		wg.Add(1)
		sem <- struct{}{}
		go func(id int64) {
			defer wg.Done()
			defer func() { <-sem }()
			if err := fn(ctx, id); err != nil && !errors.Is(err, context.Canceled) {
				r.log().Error("check failed", "id", id, "error", err.Error())
				errCh <- err
			}
		}(id)
	}
	wg.Wait()
	close(errCh)
	var errs []error
	for err := range errCh {
		errs = append(errs, err)
	}
	return errors.Join(errs...)
}

// CheckUptime runs one website uptime check, including SSL when that check is enabled.
func (r *Runner) CheckUptime(ctx context.Context, id int64, onDemand bool) error {
	row, err := r.Store.Website(ctx, id)
	if err != nil {
		return ignoreMissing(err)
	}
	if !onDemand {
		due, err := r.Store.UptimeStillDue(ctx, id, r.now())
		if err != nil || !due {
			return err
		}
	} else if !row.Diagnostic || (!row.Uptime && !row.SSL) {
		return nil
	}
	if !row.Uptime && !(onDemand && row.SSL) {
		return nil
	}
	return r.withLock(ctx, "uptime:"+strconv.FormatInt(id, 10), 120, func() error {
		fresh, err := r.Store.Website(ctx, id)
		if err != nil {
			return ignoreMissing(err)
		}
		if !onDemand {
			due, err := r.Store.UptimeStillDue(ctx, id, r.now())
			if err != nil || !due {
				return err
			}
		} else if !fresh.Diagnostic {
			return nil
		}
		return r.performUptime(ctx, fresh, onDemand)
	})
}

func (r *Runner) performUptime(ctx context.Context, row store.Website, onDemand bool) error {
	now := r.now()
	write := store.WebsiteWrite{
		WebsiteID:      row.ID,
		OnDemand:       onDemand,
		WriteHistory:   true,
		PreviousExpiry: row.SSLExpiry,
		UpdatedAt:      row.UpdatedAt,
	}
	var httpStatus, httpSummary, sslStatus, sslSummary string
	if row.Uptime {
		outcome := probe.Do(ctx, probe.Request{
			Method: http.MethodGet, URL: row.URL,
			Timeout: 10 * time.Second, ConnectTimeout: 5 * time.Second,
			Attempts: 2, RetryDelay: r.RetryDelay, Insecure: true, Roots: r.Roots,
		})
		if ctx.Err() != nil {
			return ctx.Err()
		}
		code := outcome.Code
		speed := int(math.Round(float64(outcome.Duration) / float64(time.Millisecond)))
		write.RanUptime = true
		write.HTTPCode = &code
		write.SpeedMS = &speed
		if outcome.Transport != "" {
			httpStatus = status.Danger
			httpSummary = status.SummaryTransport(outcome.Transport, "Website heartbeat")
			write.TransportType = outcome.Transport
			write.TransportMessage = outcome.ErrorText
		} else {
			httpStatus = status.WebsiteHTTP(code)
			httpSummary = status.SummaryHTTP(code)
		}
	}
	if row.SSL {
		expiry, _ := probe.Certificate(ctx, row.URL, r.Roots)
		if ctx.Err() != nil {
			return ctx.Err()
		}
		write.RanSSL = true
		write.Expiry = expiry
		sslStatus = status.SSL(expiry, now, r.loc())
		sslSummary = status.SummarySSL(expiry, now, r.loc())
	}
	write.Status = status.Worst(httpStatus, sslStatus)
	if httpStatus == "" {
		write.Status = sslStatus
	}
	write.Summary = status.CombinedSummary(write.Status, httpStatus, httpSummary, sslStatus, sslSummary, write.Expiry != nil)
	return r.Store.SaveWebsite(ctx, write)
}

// CheckSSL runs the certificate job. Uptime websites only refresh the date and the reminder.
func (r *Runner) CheckSSL(ctx context.Context, id int64) error {
	return r.withLock(ctx, "ssl:"+strconv.FormatInt(id, 10), 90, func() error {
		due, err := r.Store.SSLStillDue(ctx, id, r.now())
		if err != nil || !due {
			return err
		}
		row, err := r.Store.Website(ctx, id)
		if err != nil {
			return ignoreMissing(err)
		}
		if !row.SSL {
			return nil
		}
		now := r.now()
		expiry, _ := probe.Certificate(ctx, row.URL, r.Roots)
		if ctx.Err() != nil {
			return ctx.Err()
		}
		write := store.WebsiteWrite{
			WebsiteID: row.ID, RanSSL: true, Expiry: expiry,
			PreviousExpiry: row.SSLExpiry, UpdatedAt: row.UpdatedAt,
			Reminder: r.reminderDue(row, expiry, now),
		}
		if row.Uptime {
			return r.Store.SaveWebsite(ctx, write)
		}
		write.WriteHistory = true
		write.Status = status.SSL(expiry, now, r.loc())
		write.Summary = status.SummarySSL(expiry, now, r.loc())
		return r.Store.SaveWebsite(ctx, write)
	})
}

func (r *Runner) reminderDue(row store.Website, expiry *time.Time, now time.Time) bool {
	if expiry == nil || !status.ReminderDue(expiry, now, r.loc()) {
		return false
	}
	if !row.ReminderSent.Valid || row.ReminderSent.String == "" {
		return true
	}
	sent, err := time.ParseInLocation("2006-01-02 15:04:05", row.ReminderSent.String, time.UTC)
	if err != nil {
		return true
	}
	return !sent.After(now.Add(-24 * time.Hour))
}

// CheckAPI runs one monitor. scheduled runs are capped at 90s and 3 attempts.
func (r *Runner) CheckAPI(ctx context.Context, id int64, onDemand bool) error {
	row, err := r.Store.API(ctx, id)
	if err != nil {
		return ignoreMissing(err)
	}
	if !row.Enabled {
		return nil
	}
	if !onDemand {
		due, err := r.Store.APIStillDue(ctx, id, r.now())
		if err != nil || !due {
			return err
		}
	} else if !row.Diagnostic {
		return nil
	}
	return r.withLock(ctx, "api:"+strconv.FormatInt(id, 10), 450, func() error {
		fresh, err := r.Store.API(ctx, id)
		if err != nil {
			return ignoreMissing(err)
		}
		if !fresh.Enabled {
			return nil
		}
		if !onDemand {
			due, err := r.Store.APIStillDue(ctx, id, r.now())
			if err != nil || !due {
				return err
			}
		} else if !fresh.Diagnostic {
			return nil
		}
		return r.performAPI(ctx, fresh, onDemand)
	})
}

func (r *Runner) performAPI(ctx context.Context, row store.APIMonitor, onDemand bool) error {
	timeout := 90
	attempts := 3
	if !onDemand {
		if row.TimeoutSeconds.Valid && row.TimeoutSeconds.Int64 > 0 && int(row.TimeoutSeconds.Int64) < timeout {
			timeout = int(row.TimeoutSeconds.Int64)
		}
		if row.RetryCount.Valid && int(row.RetryCount.Int64) < attempts {
			attempts = int(row.RetryCount.Int64)
		}
	} else {
		if row.TimeoutSeconds.Valid && row.TimeoutSeconds.Int64 > 0 {
			timeout = int(row.TimeoutSeconds.Int64)
		} else {
			timeout = 30
		}
		if row.RetryCount.Valid {
			attempts = int(row.RetryCount.Int64)
		}
	}
	if attempts < 1 {
		attempts = 1
	}
	headers := r.headers(row.Headers.String)
	body, contentType := requestBody(row.BodyType.String, r.secret(row.Body.String))
	outcome := probe.Do(ctx, probe.Request{
		Method: row.Method, URL: row.URL, Headers: headers,
		Body: body, ContentType: contentType,
		Timeout: time.Duration(timeout) * time.Second, ConnectTimeout: time.Duration(timeout) * time.Second,
		Attempts: attempts, RetryDelay: r.RetryDelay, Roots: r.Roots,
	})
	if ctx.Err() != nil {
		return ctx.Err()
	}
	return r.Store.SaveAPI(ctx, r.apiWrite(row, onDemand, outcome, timeout, attempts, headers))
}

func (r *Runner) apiWrite(row store.APIMonitor, onDemand bool, outcome probe.Outcome, timeout, attempts int, requestHeaders map[string]string) store.APIWrite {
	var expected *int
	if row.ExpectedStatus.Valid {
		value := int(row.ExpectedStatus.Int64)
		expected = &value
	}
	results := make([]assert.Result, 0)
	if expected != nil && outcome.Code != *expected {
		results = append(results, assert.Result{
			Path: "_http_status", Type: "status_code", Passed: false,
			Message: fmt.Sprintf("Expected HTTP status %d, got %d.", *expected, outcome.Code),
			Actual:  outcome.Code, Expected: *expected, HasActual: true, HasExpected: true,
		})
	}
	needsJSON := len(row.Assertions) > 0 || (row.DataPath.Valid && row.DataPath.String != "")
	var parsed any
	if outcome.Transport == "" && needsJSON {
		decoded, ok := assert.DecodeJSON(outcome.Body)
		if !ok {
			results = append(results, assert.Result{
				Path: "_response_body", Type: "json_valid", Passed: false,
				Message: "Invalid JSON response", Actual: "invalid", Expected: "valid JSON",
				HasActual: true, HasExpected: true,
			})
		} else {
			parsed = decoded
			dataPath := ""
			if row.DataPath.Valid {
				dataPath = row.DataPath.String
			}
			results = append(results, assert.Evaluate(parsed, row.Assertions, dataPath)...)
		}
	}
	failed := false
	for _, item := range results {
		if !item.Passed {
			failed = true
			break
		}
	}
	responseMS := int(math.Round(float64(outcome.Duration) / float64(time.Millisecond)))
	maxMS := 0
	var maxPtr *int
	if row.MaxResponseMS.Valid && row.MaxResponseMS.Int64 > 0 {
		maxMS = int(row.MaxResponseMS.Int64)
		maxPtr = &maxMS
	}
	slow := maxMS > 0 && responseMS > maxMS
	state := status.API(outcome.Code, expected, failed, slow)
	summary := status.SummaryAPI(state, outcome.Code, outcome.Transport, responseMS, maxMS)
	var saved any
	if state != status.Healthy && row.SaveFailed {
		switch {
		case parsed != nil:
			saved = parsed
		case len(outcome.Body) > 0:
			saved = map[string]any{"__checky_raw_body__": string(outcome.Body)}
		case outcome.ErrorText != "":
			saved = map[string]any{"__checky_error__": outcome.ErrorText}
		}
	}
	return store.APIWrite{
		MonitorID: row.ID, OnDemand: onDemand, Success: state == status.Healthy,
		Code: outcome.Code, ResponseMS: responseMS, ElapsedMS: responseMS,
		MaxMS: maxPtr, TimeoutSeconds: timeout, Attempts: attempts,
		Status: state, Summary: summary, TransportType: outcome.Transport,
		TransportMessage: outcome.ErrorText, Failed: results,
		RequestHeaders:  store.MaskHeaders(requestHeaders),
		ResponseHeaders: store.MaskHeaders(flattenHeaders(outcome.Headers)),
		Body:            saved, SaveBody: row.SaveFailed,
	}
}

func (r *Runner) withLock(ctx context.Context, key string, lease int, fn func() error) error {
	owner, err := store.NewOwner()
	if err != nil {
		return err
	}
	ok, err := r.Store.Claim(ctx, key, owner, lease)
	if err != nil || !ok {
		return err
	}
	defer func() {
		releaseCtx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
		defer cancel()
		if err := r.Store.Release(releaseCtx, key, owner); err != nil {
			r.log().Error("release lock failed", "lock", key, "error", err.Error())
		}
	}()
	return fn()
}

func (r *Runner) shadowUptime(ctx context.Context) error {
	items, err := r.Store.ShadowUptime(ctx)
	if err != nil {
		return err
	}
	return r.parallel(ctx, idsOf(items), func(ctx context.Context, id int64) error {
		item := findShadow(items, id)
		row, err := r.Store.Website(ctx, id)
		if err != nil {
			return ignoreMissing(err)
		}
		outcome := probe.Do(ctx, probe.Request{
			Method: http.MethodGet, URL: row.URL,
			Timeout: 10 * time.Second, ConnectTimeout: 5 * time.Second,
			Attempts: 2, RetryDelay: r.RetryDelay, Insecure: true, Roots: r.Roots,
		})
		if ctx.Err() != nil {
			return ctx.Err()
		}
		goStatus := status.Danger
		if outcome.Transport == "" {
			goStatus = status.WebsiteHTTP(outcome.Code)
		}
		if row.SSL {
			expiry, _ := probe.Certificate(ctx, row.URL, r.Roots)
			goStatus = status.Worst(goStatus, status.SSL(expiry, r.now(), r.loc()))
		}
		return r.Store.SaveShadow(ctx, "uptime", id, item.LaravelStatus, goStatus, compare(item.LaravelStatus, goStatus), "")
	})
}

func (r *Runner) shadowSSL(ctx context.Context) error {
	items, err := r.Store.ShadowSSL(ctx)
	if err != nil {
		return err
	}
	return r.parallel(ctx, idsOf(items), func(ctx context.Context, id int64) error {
		item := findShadow(items, id)
		row, err := r.Store.Website(ctx, id)
		if err != nil {
			return ignoreMissing(err)
		}
		expiry, _ := probe.Certificate(ctx, row.URL, r.Roots)
		goStatus := status.SSL(expiry, r.now(), r.loc())
		return r.Store.SaveShadow(ctx, "ssl", id, item.LaravelStatus, goStatus, compare(item.LaravelStatus, goStatus), "")
	})
}

func (r *Runner) shadowAPI(ctx context.Context) error {
	items, err := r.Store.ShadowAPI(ctx)
	if err != nil {
		return err
	}
	return r.parallel(ctx, idsOf(items), func(ctx context.Context, id int64) error {
		item := findShadow(items, id)
		row, err := r.Store.API(ctx, id)
		if err != nil {
			return ignoreMissing(err)
		}
		timeout := 90
		attempts := 3
		if row.TimeoutSeconds.Valid && row.TimeoutSeconds.Int64 > 0 && int(row.TimeoutSeconds.Int64) < timeout {
			timeout = int(row.TimeoutSeconds.Int64)
		}
		if row.RetryCount.Valid && int(row.RetryCount.Int64) < attempts {
			attempts = int(row.RetryCount.Int64)
		}
		if attempts < 1 {
			attempts = 1
		}
		headers := r.headers(row.Headers.String)
		body, contentType := requestBody(row.BodyType.String, r.secret(row.Body.String))
		outcome := probe.Do(ctx, probe.Request{
			Method: row.Method, URL: row.URL, Headers: headers, Body: body, ContentType: contentType,
			Timeout: time.Duration(timeout) * time.Second, ConnectTimeout: time.Duration(timeout) * time.Second,
			Attempts: attempts, RetryDelay: r.RetryDelay, Roots: r.Roots,
		})
		if ctx.Err() != nil {
			return ctx.Err()
		}
		goStatus := r.apiWrite(row, false, outcome, timeout, attempts, headers).Status
		return r.Store.SaveShadow(ctx, "api", id, item.LaravelStatus, goStatus, compare(item.LaravelStatus, goStatus), "")
	})
}

func compare(laravelStatus, goStatus string) string {
	if laravelStatus == "" || goStatus == "" {
		return "skip"
	}
	if laravelStatus == goStatus {
		return "match"
	}
	return "mismatch"
}

func idsOf(items []store.ShadowCandidate) []int64 {
	out := make([]int64, 0, len(items))
	for _, item := range items {
		out = append(out, item.ID)
	}
	return out
}

func findShadow(items []store.ShadowCandidate, id int64) store.ShadowCandidate {
	for _, item := range items {
		if item.ID == id {
			return item
		}
	}
	return store.ShadowCandidate{ID: id}
}

func (r *Runner) secret(stored string) string {
	if stored == "" || !r.HasKey {
		return stored
	}
	plain, err := r.Key.UnwrapSecret(stored)
	if err != nil {
		return ""
	}
	return plain
}

func (r *Runner) headers(stored string) map[string]string {
	plain := r.secret(stored)
	if strings.TrimSpace(plain) == "" {
		return map[string]string{}
	}
	var raw map[string]any
	if json.Unmarshal([]byte(plain), &raw) != nil {
		return map[string]string{}
	}
	out := map[string]string{}
	for key, value := range raw {
		out[key] = fmt.Sprint(value)
	}
	return out
}

func requestBody(kind, raw string) ([]byte, string) {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return nil, ""
	}
	switch strings.ToLower(kind) {
	case "json":
		return []byte(raw), "application/json"
	case "form":
		values := url.Values{}
		var object map[string]any
		if json.Unmarshal([]byte(raw), &object) == nil {
			for key, value := range object {
				values.Set(key, fmt.Sprint(value))
			}
		} else {
			parsed, _ := url.ParseQuery(raw)
			values = parsed
		}
		return []byte(values.Encode()), "application/x-www-form-urlencoded"
	case "raw":
		return []byte(raw), "text/plain"
	default:
		return nil, ""
	}
}

func flattenHeaders(headers http.Header) map[string]string {
	out := map[string]string{}
	for key, values := range headers {
		out[key] = strings.Join(values, ", ")
	}
	return out
}

func ignoreMissing(err error) error {
	if err == nil || errors.Is(err, sql.ErrNoRows) {
		return nil
	}
	return err
}
