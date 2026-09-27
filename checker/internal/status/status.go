// Package status matches PackageHealthStatusService and the combined website summary.
package status

import (
	"fmt"
	"time"
)

const (
	Healthy = "healthy"
	Warning = "warning"
	Danger  = "danger"
)

func WebsiteHTTP(code int) string {
	switch {
	case code == 0 || code >= 500:
		return Danger
	case code >= 400:
		return Warning
	default:
		return Healthy
	}
}

func SummaryHTTP(code int) string {
	switch WebsiteHTTP(code) {
	case Danger:
		return fmt.Sprintf("Website heartbeat failed with HTTP status %d.", code)
	case Warning:
		return fmt.Sprintf("Website heartbeat is degraded with HTTP status %d.", code)
	default:
		return fmt.Sprintf("Website heartbeat succeeded with HTTP status %d.", code)
	}
}

func SummaryTransport(kind, subject string) string {
	if subject == "" {
		subject = "Website heartbeat"
	}
	switch kind {
	case "dns":
		return subject + " failed before an HTTP response: DNS lookup failed."
	case "timeout":
		return subject + " failed before an HTTP response: the request timed out."
	case "tls":
		return subject + " failed before an HTTP response: TLS/SSL negotiation failed."
	case "connection":
		return subject + " failed before an HTTP response: the connection could not be established."
	default:
		return subject + " failed before an HTTP response because of a transport error."
	}
}

// SSL reports the certificate status. A nil expiry means the handshake did not yield a date.
func SSL(expiry *time.Time, now time.Time, loc *time.Location) string {
	if expiry == nil {
		return Danger
	}
	if expiry.Before(now) {
		return Danger
	}
	if daysUntil(*expiry, now, loc) <= 14 {
		return Warning
	}
	return Healthy
}

func SummarySSL(expiry *time.Time, now time.Time, loc *time.Location) string {
	if expiry == nil {
		return "SSL certificate check failed before an expiry date could be read."
	}
	if expiry.Before(now) {
		days := abs(daysUntil(*expiry, now, loc))
		if days == 0 {
			return "SSL certificate expired today."
		}
		return fmt.Sprintf("SSL certificate expired %d day(s) ago.", days)
	}
	days := daysUntil(*expiry, now, loc)
	if days <= 14 {
		return fmt.Sprintf("SSL certificate expires in %d day(s).", days)
	}
	return fmt.Sprintf("SSL certificate is valid for %d day(s).", days)
}

func Worst(parts ...string) string {
	rank := map[string]int{Healthy: 0, Warning: 1, Danger: 2}
	best := Healthy
	for _, part := range parts {
		if part == "" {
			continue
		}
		if _, ok := rank[part]; !ok {
			part = Warning
		}
		if rank[part] > rank[best] {
			best = part
		}
	}
	return best
}

// CombinedSummary follows LogUptimeSslJob::summaryForCombinedStatus.
// hasExpiry is false when the handshake did not return a date, even though the SSL summary is still set.
func CombinedSummary(overall, httpStatus, httpSummary, sslStatus, sslSummary string, hasExpiry bool) string {
	if httpStatus == "" {
		return sslSummary
	}
	if sslStatus != "" && overall == sslStatus && overall != httpStatus {
		return sslSummary
	}
	if sslStatus != "" && hasExpiry && overall == sslStatus && overall != Healthy {
		return httpSummary + " " + sslSummary
	}
	if httpSummary != "" {
		return httpSummary
	}
	return "Website diagnostics completed."
}

func API(code int, expected *int, failedAssertion, slow bool) string {
	if code == 0 {
		return Danger
	}
	if expected != nil && code == *expected {
		if failedAssertion || slow {
			return Warning
		}
		return Healthy
	}
	if expected != nil {
		if code >= 500 {
			return Danger
		}
		return Warning
	}
	switch {
	case code >= 500:
		return Danger
	case code >= 400:
		return Warning
	case failedAssertion || slow:
		return Warning
	default:
		return Healthy
	}
}

func SummaryAPI(state string, code int, transport string, responseMS, maxMS int) string {
	switch state {
	case Danger:
		if code == 0 && transport != "" {
			return SummaryTransport(transport, "API check")
		}
		return fmt.Sprintf("API check failed with HTTP status %d.", code)
	case Warning:
		if maxMS > 0 && responseMS > maxMS {
			return fmt.Sprintf("API check exceeded the %dms response-time warning threshold (%dms).", maxMS, responseMS)
		}
		return fmt.Sprintf("API check is degraded with HTTP status %d.", code)
	default:
		return fmt.Sprintf("API check succeeded with HTTP status %d.", code)
	}
}

// ReminderDue is true on the SSL reminder days and every day after expiry.
func ReminderDue(expiry *time.Time, now time.Time, loc *time.Location) bool {
	if expiry == nil {
		return false
	}
	days := daysUntil(*expiry, now, loc)
	switch days {
	case 14, 7, 3, 2, 1, 0:
		return true
	default:
		return days < 0
	}
}

func daysUntil(expiry, now time.Time, loc *time.Location) int {
	if loc == nil {
		loc = time.UTC
	}
	exp := expiry.In(loc)
	cur := now.In(loc)
	expDay := time.Date(exp.Year(), exp.Month(), exp.Day(), 0, 0, 0, 0, loc)
	today := time.Date(cur.Year(), cur.Month(), cur.Day(), 0, 0, 0, 0, loc)
	return int(expDay.Sub(today).Hours() / 24)
}

func abs(v int) int {
	if v < 0 {
		return -v
	}
	return v
}

// TransitionEvent returns heartbeat, recovered, or empty when nothing should be sent.
func TransitionEvent(previous, next string) string {
	if (next == Warning || next == Danger) && previous != next {
		return "heartbeat"
	}
	if next == Healthy && (previous == Warning || previous == Danger) {
		return "recovered"
	}
	return ""
}
