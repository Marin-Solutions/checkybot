// Package store reads and writes the same MySQL rows the Laravel checkers use.
package store

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/Marin-Solutions/checkybot/checker/internal/assert"
	mysql "github.com/go-sql-driver/mysql"
)

// Store is the shared database. It does not keep request state.
type Store struct {
	DB *sql.DB
}

func Open(dsn string, maxOpen int) (*Store, error) {
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		return nil, fmt.Errorf("open database: %w", err)
	}
	if maxOpen < 2 {
		maxOpen = 2
	}
	db.SetMaxOpenConns(maxOpen)
	db.SetMaxIdleConns(maxOpen)
	db.SetConnMaxLifetime(5 * time.Minute)
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := db.PingContext(ctx); err != nil {
		_ = db.Close()
		return nil, fmt.Errorf("ping database: %w", err)
	}
	return &Store{DB: db}, nil
}

func (s *Store) Close() error { return s.DB.Close() }

func (s *Store) Settings(ctx context.Context) (map[string]string, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT setting_key, setting_value FROM checker_settings`)
	if err != nil {
		return nil, fmt.Errorf("read checker settings: %w", err)
	}
	defer rows.Close()
	out := map[string]string{}
	for rows.Next() {
		var key, value string
		if err := rows.Scan(&key, &value); err != nil {
			return nil, err
		}
		out[key] = value
	}
	return out, rows.Err()
}

// Claim inserts a lock or takes over an expired one. A held lock returns false.
// Two overlapping claims cannot both succeed; the loser waits on the row only
// while the winner's insert transaction is still open, then sees the committed owner.
func (s *Store) Claim(ctx context.Context, key, owner string, leaseSeconds int) (bool, error) {
	if leaseSeconds < 1 {
		leaseSeconds = 1
	}
	conn, err := s.DB.Conn(ctx)
	if err != nil {
		return false, fmt.Errorf("lock connection: %w", err)
	}
	defer conn.Close()
	if _, err := conn.ExecContext(ctx, `SET TRANSACTION ISOLATION LEVEL READ COMMITTED`); err != nil {
		return false, fmt.Errorf("set isolation: %w", err)
	}
	tx, err := conn.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return false, fmt.Errorf("begin lock: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	_, err = tx.ExecContext(ctx, `
		INSERT INTO checker_locks (lock_key, owner, expires_at, created_at, updated_at)
		VALUES (?, ?, UTC_TIMESTAMP(6) + INTERVAL ? SECOND, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
	`, key, owner, leaseSeconds)
	if err != nil {
		if !isDuplicate(err) {
			if isLockWait(err) {
				return false, nil
			}
			return false, fmt.Errorf("insert lock: %w", err)
		}
		res, err := tx.ExecContext(ctx, `
			UPDATE checker_locks
			SET owner = ?, expires_at = UTC_TIMESTAMP(6) + INTERVAL ? SECOND, updated_at = UTC_TIMESTAMP(6)
			WHERE lock_key = ? AND expires_at <= UTC_TIMESTAMP(6)
		`, owner, leaseSeconds, key)
		if err != nil {
			if isLockWait(err) {
				return false, nil
			}
			return false, fmt.Errorf("take over lock: %w", err)
		}
		n, err := res.RowsAffected()
		if err != nil {
			return false, err
		}
		if n != 1 {
			return false, nil
		}
	}
	if err := tx.Commit(); err != nil {
		return false, fmt.Errorf("commit lock: %w", err)
	}
	return true, nil
}

func (s *Store) Release(ctx context.Context, key, owner string) error {
	_, err := s.DB.ExecContext(ctx, `DELETE FROM checker_locks WHERE lock_key = ? AND owner = ?`, key, owner)
	if err != nil {
		return fmt.Errorf("release lock: %w", err)
	}
	return nil
}

func NewOwner() (string, error) {
	buf := make([]byte, 16)
	if _, err := rand.Read(buf); err != nil {
		return "", err
	}
	return hex.EncodeToString(buf), nil
}

func isDuplicate(err error) bool {
	var mysqlErr *mysql.MySQLError
	return errors.As(err, &mysqlErr) && mysqlErr.Number == 1062
}

func isLockWait(err error) bool {
	var mysqlErr *mysql.MySQLError
	return errors.As(err, &mysqlErr) && (mysqlErr.Number == 1205 || mysqlErr.Number == 1213)
}

type Website struct {
	ID              int64
	URL             string
	Uptime          bool
	SSL             bool
	Interval        sql.NullInt64
	PackageInterval sql.NullString
	Source          sql.NullString
	SSLExpiry       sql.NullString
	Status          sql.NullString
	UpdatedAt       sql.NullString
	Diagnostic      bool
	ReminderSent    sql.NullString
}

func (s *Store) Website(ctx context.Context, id int64) (Website, error) {
	var row Website
	var uptime, sslCheck, diagnostic int
	err := s.DB.QueryRowContext(ctx, `
		SELECT id, url, uptime_check, ssl_check, uptime_interval, package_interval, source,
			DATE_FORMAT(ssl_expiry_date, '%Y-%m-%d %H:%i:%s'),
			current_status,
			DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s'),
			diagnostic_queued_at IS NOT NULL,
			DATE_FORMAT(ssl_expiry_reminder_sent_at, '%Y-%m-%d %H:%i:%s')
		FROM websites
		WHERE id = ? AND deleted_at IS NULL
	`, id).Scan(
		&row.ID, &row.URL, &uptime, &sslCheck, &row.Interval, &row.PackageInterval, &row.Source,
		&row.SSLExpiry, &row.Status, &row.UpdatedAt, &diagnostic, &row.ReminderSent,
	)
	if err != nil {
		return Website{}, err
	}
	row.Uptime = uptime == 1
	row.SSL = sslCheck == 1
	row.Diagnostic = diagnostic == 1
	return row, nil
}

type APIMonitor struct {
	ID             int64
	URL            string
	Method         string
	Headers        sql.NullString
	BodyType       sql.NullString
	Body           sql.NullString
	ExpectedStatus sql.NullInt64
	TimeoutSeconds sql.NullInt64
	RetryCount     sql.NullInt64
	MaxResponseMS  sql.NullInt64
	DataPath       sql.NullString
	Enabled        bool
	SaveFailed     bool
	Status         sql.NullString
	Diagnostic     bool
	Assertions     []assert.Assertion
}

func (s *Store) API(ctx context.Context, id int64) (APIMonitor, error) {
	var row APIMonitor
	var enabled, saveFailed, diagnostic int
	err := s.DB.QueryRowContext(ctx, `
		SELECT id, url, COALESCE(http_method, 'GET'), headers, request_body_type, request_body,
			expected_status, timeout_seconds, retry_count, max_response_time_ms, data_path,
			is_enabled, save_failed_response, current_status, diagnostic_queued_at IS NOT NULL
		FROM monitor_apis
		WHERE id = ? AND deleted_at IS NULL
	`, id).Scan(
		&row.ID, &row.URL, &row.Method, &row.Headers, &row.BodyType, &row.Body,
		&row.ExpectedStatus, &row.TimeoutSeconds, &row.RetryCount, &row.MaxResponseMS, &row.DataPath,
		&enabled, &saveFailed, &row.Status, &diagnostic,
	)
	if err != nil {
		return APIMonitor{}, err
	}
	row.Enabled = enabled == 1
	row.SaveFailed = saveFailed == 1
	row.Diagnostic = diagnostic == 1
	assertions, err := s.DB.QueryContext(ctx, `
		SELECT COALESCE(data_path, ''), COALESCE(assertion_type, ''), COALESCE(expected_type, ''),
			COALESCE(comparison_operator, ''), COALESCE(expected_value, ''), COALESCE(regex_pattern, ''), is_active
		FROM monitor_api_assertions
		WHERE monitor_api_id = ?
		ORDER BY sort_order, id
	`, id)
	if err != nil {
		return APIMonitor{}, err
	}
	defer assertions.Close()
	for assertions.Next() {
		var rule assert.Assertion
		var active int
		if err := assertions.Scan(&rule.Path, &rule.Type, &rule.ExpectedType, &rule.Operator, &rule.Expected, &rule.Pattern, &active); err != nil {
			return APIMonitor{}, err
		}
		rule.Active = active == 1
		row.Assertions = append(row.Assertions, rule)
	}
	return row, assertions.Err()
}

// DueUptime returns websites whose scheduled uptime check is due at the given minute.
func (s *Store) DueUptime(ctx context.Context, minute time.Time) ([]int64, error) {
	return s.ids(ctx, `
		SELECT id FROM websites
		WHERE deleted_at IS NULL AND uptime_check = 1
			AND uptime_interval IN (1, 5, 10, 15, 30, 60, 360, 720, 1440)
			AND (
				latest_scheduled_result_at IS NULL
				OR DATE_ADD(DATE_FORMAT(latest_scheduled_result_at, '%Y-%m-%d %H:%i:00'), INTERVAL uptime_interval MINUTE)
					<= DATE_FORMAT(?, '%Y-%m-%d %H:%i:00')
			)
	`, minute.UTC().Format("2006-01-02 15:04:05"))
}

func (s *Store) DiagnosticWebsites(ctx context.Context) ([]int64, error) {
	return s.ids(ctx, `
		SELECT id FROM websites
		WHERE deleted_at IS NULL AND diagnostic_queued_at IS NOT NULL
			AND (uptime_check = 1 OR ssl_check = 1)
	`)
}

func (s *Store) DueSSL(ctx context.Context, now time.Time) ([]int64, error) {
	stamp := now.UTC().Format("2006-01-02 15:04:05")
	minute := now.UTC().Format("2006-01-02 15:04:00")
	return s.ids(ctx, dueSSLSQL, stamp, stamp, minute)
}

func (s *Store) DueAPI(ctx context.Context, minute time.Time) ([]int64, error) {
	return s.ids(ctx, dueAPISQL, minute.UTC().Format("2006-01-02 15:04:00"))
}

func (s *Store) DiagnosticAPIs(ctx context.Context) ([]int64, error) {
	return s.ids(ctx, `
		SELECT id FROM monitor_apis
		WHERE deleted_at IS NULL AND is_enabled = 1 AND diagnostic_queued_at IS NOT NULL
	`)
}

func (s *Store) UptimeStillDue(ctx context.Context, id int64, minute time.Time) (bool, error) {
	var n int
	err := s.DB.QueryRowContext(ctx, `
		SELECT COUNT(*) FROM websites
		WHERE id = ? AND deleted_at IS NULL AND uptime_check = 1
			AND uptime_interval IN (1, 5, 10, 15, 30, 60, 360, 720, 1440)
			AND (
				latest_scheduled_result_at IS NULL
				OR DATE_ADD(DATE_FORMAT(latest_scheduled_result_at, '%Y-%m-%d %H:%i:00'), INTERVAL uptime_interval MINUTE)
					<= DATE_FORMAT(?, '%Y-%m-%d %H:%i:00')
			)
	`, id, minute.UTC().Format("2006-01-02 15:04:05")).Scan(&n)
	return n == 1, err
}

func (s *Store) APIStillDue(ctx context.Context, id int64, minute time.Time) (bool, error) {
	var n int
	err := s.DB.QueryRowContext(ctx, dueAPISQLForID, id, minute.UTC().Format("2006-01-02 15:04:00")).Scan(&n)
	return n == 1, err
}

func (s *Store) SSLStillDue(ctx context.Context, id int64, now time.Time) (bool, error) {
	var n int
	stamp := now.UTC().Format("2006-01-02 15:04:05")
	minute := now.UTC().Format("2006-01-02 15:04:00")
	err := s.DB.QueryRowContext(ctx, dueSSLSQLForID, id, stamp, stamp, minute).Scan(&n)
	return n == 1, err
}

func (s *Store) ids(ctx context.Context, query string, args ...any) ([]int64, error) {
	rows, err := s.DB.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		out = append(out, id)
	}
	return out, rows.Err()
}

// WebsiteWrite is the row change for one uptime or ssl-only check.
type WebsiteWrite struct {
	WebsiteID        int64
	OnDemand         bool
	WriteHistory     bool
	RanUptime        bool
	RanSSL           bool
	HTTPCode         *int
	SpeedMS          *int
	Status           string
	Summary          string
	TransportType    string
	TransportMessage string
	Expiry           *time.Time
	PreviousExpiry   sql.NullString
	UpdatedAt        sql.NullString
	ClearReminder    bool
	Event            string
	Reminder         bool
	SSLOnly          bool
}

func (s *Store) SaveWebsite(ctx context.Context, write WebsiteWrite) error {
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback() }()

	var previous sql.NullString
	var lockedExpiry sql.NullString
	err = tx.QueryRowContext(ctx, `
		SELECT current_status, DATE_FORMAT(ssl_expiry_date, '%Y-%m-%d %H:%i:%s')
		FROM websites WHERE id = ? AND deleted_at IS NULL FOR UPDATE
	`, write.WebsiteID).Scan(&previous, &lockedExpiry)
	if errors.Is(err, sql.ErrNoRows) {
		return nil
	}
	if err != nil {
		return fmt.Errorf("lock website: %w", err)
	}

	var expiry any
	if write.Expiry != nil {
		expiry = write.Expiry.UTC().Format("2006-01-02 15:04:05")
	}
	var historyID int64
	if write.WriteHistory {
		var code any
		if write.HTTPCode != nil {
			code = *write.HTTPCode
		}
		var speed any
		if write.SpeedMS != nil {
			speed = *write.SpeedMS
		}
		var transportType, transportMessage any
		if write.TransportType != "" {
			transportType = write.TransportType
			transportMessage = trim(write.TransportMessage, 1000)
		}
		source := "scheduled"
		onDemand := 0
		if write.OnDemand {
			source = "on_demand"
			onDemand = 1
		}
		res, err := tx.ExecContext(ctx, `
			INSERT INTO website_log_history (
				website_id, ssl_expiry_date, http_status_code, speed, status, summary,
				transport_error_type, transport_error_message, run_source, is_on_demand, created_at, updated_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
		`, write.WebsiteID, expiry, code, speed, write.Status, write.Summary, transportType, transportMessage, source, onDemand)
		if err != nil {
			return fmt.Errorf("insert website history: %w", err)
		}
		historyID, _ = res.LastInsertId()
		if !write.OnDemand {
			if _, err := tx.ExecContext(ctx, `
				UPDATE websites
				SET latest_scheduled_result_at = UTC_TIMESTAMP()
				WHERE id = ? AND (latest_scheduled_result_at IS NULL OR latest_scheduled_result_at < UTC_TIMESTAMP())
			`, write.WebsiteID); err != nil {
				return err
			}
		}
	}
	if write.RanSSL {
		changed := write.Expiry != nil && expiryDayChanged(lockedExpiry, *write.Expiry)
		if !changed && write.Expiry != nil && !lockedExpiry.Valid {
			var previousHistory sql.NullString
			_ = tx.QueryRowContext(ctx, `
				SELECT DATE_FORMAT(ssl_expiry_date, '%Y-%m-%d %H:%i:%s')
				FROM website_log_history
				WHERE website_id = ? AND id < ? AND ssl_expiry_date IS NOT NULL
				ORDER BY created_at DESC, id DESC LIMIT 1
			`, write.WebsiteID, historyID).Scan(&previousHistory)
			changed = expiryDayChanged(previousHistory, *write.Expiry)
		}
		clear := 0
		if changed {
			clear = 1
		}
		if _, err := tx.ExecContext(ctx, `
			UPDATE websites
			SET ssl_expiry_date = ?,
				ssl_expiry_reminder_sent_at = IF(? = 1, NULL, ssl_expiry_reminder_sent_at)
			WHERE id = ?
				AND ((? IS NULL AND updated_at IS NULL) OR updated_at = ?)
				AND ((? IS NULL AND ssl_expiry_date IS NULL) OR ssl_expiry_date = ?)
		`, expiry, clear, write.WebsiteID, nullString(write.UpdatedAt), nullString(write.UpdatedAt), nullString(write.PreviousExpiry), nullString(write.PreviousExpiry)); err != nil {
			return fmt.Errorf("update ssl expiry: %w", err)
		}
	}
	if write.WriteHistory {
		if _, err := tx.ExecContext(ctx, `
			UPDATE websites SET current_status = ?, status_summary = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?
		`, write.Status, write.Summary, write.WebsiteID); err != nil {
			return err
		}
	}
	if write.OnDemand && write.WriteHistory {
		if _, err := tx.ExecContext(ctx, `UPDATE websites SET diagnostic_queued_at = NULL WHERE id = ?`, write.WebsiteID); err != nil {
			return err
		}
	}
	if write.WriteHistory {
		prev := ""
		if previous.Valid {
			prev = previous.String
		}
		if event := transition(prev, write.Status); event != "" {
			if err := insertOutbox(ctx, tx, "website_transition", write.WebsiteID, event, write.Status, write.Summary); err != nil {
				return err
			}
		}
	}
	if write.Reminder {
		if err := insertOutboxOnce(ctx, tx, "ssl_reminder", write.WebsiteID, "", "", ""); err != nil {
			return err
		}
	}
	return tx.Commit()
}

type APIWrite struct {
	MonitorID        int64
	OnDemand         bool
	Success          bool
	Code             int
	ResponseMS       int
	ElapsedMS        int
	MaxMS            *int
	TimeoutSeconds   int
	Attempts         int
	Status           string
	Summary          string
	TransportType    string
	TransportMessage string
	Failed           []assert.Result
	RequestHeaders   map[string]string
	ResponseHeaders  map[string]string
	Body             any
	SaveBody         bool
}

func (s *Store) SaveAPI(ctx context.Context, write APIWrite) error {
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback() }()
	var previous sql.NullString
	err = tx.QueryRowContext(ctx, `
		SELECT current_status FROM monitor_apis WHERE id = ? AND deleted_at IS NULL FOR UPDATE
	`, write.MonitorID).Scan(&previous)
	if errors.Is(err, sql.ErrNoRows) {
		return nil
	}
	if err != nil {
		return fmt.Errorf("lock api: %w", err)
	}
	failed := make([]map[string]any, 0)
	for _, item := range write.Failed {
		if item.Passed {
			continue
		}
		row := map[string]any{
			"path": item.Path, "type": item.Type, "message": item.Message,
		}
		if item.HasActual {
			row["actual"] = capValue(item.Actual)
		}
		if item.HasExpected {
			row["expected"] = capValue(item.Expected)
		}
		failed = append(failed, row)
	}
	failedJSON, err := json.Marshal(failed)
	if err != nil {
		return err
	}
	var bodyJSON any
	if !write.Success && write.SaveBody && write.Body != nil {
		encoded, err := json.Marshal(redact(write.Body))
		if err != nil {
			return err
		}
		bodyJSON = string(encoded)
	}
	reqJSON, _ := json.Marshal(write.RequestHeaders)
	resJSON, _ := json.Marshal(write.ResponseHeaders)
	var maxMS any
	if write.MaxMS != nil {
		maxMS = *write.MaxMS
	}
	var transportType, transportMessage any
	if write.TransportType != "" {
		transportType = write.TransportType
		transportMessage = trim(write.TransportMessage, 1000)
	}
	source := "scheduled"
	onDemand := 0
	if write.OnDemand {
		source = "on_demand"
		onDemand = 1
	}
	success := 0
	if write.Success {
		success = 1
	}
	if _, err := tx.ExecContext(ctx, `
		INSERT INTO monitor_api_results (
			monitor_api_id, is_success, consecutive_count, response_time_ms, max_response_time_ms,
			effective_timeout_seconds, retry_count, elapsed_wall_time_ms, http_code, failed_assertions,
			response_body, status, summary, transport_error_type, transport_error_message,
			request_headers, response_headers, run_source, is_on_demand, created_at, updated_at
		) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
	`, write.MonitorID, success, write.ResponseMS, maxMS, write.TimeoutSeconds, write.Attempts, write.ElapsedMS,
		write.Code, string(failedJSON), bodyJSON, write.Status, write.Summary, transportType, transportMessage,
		string(reqJSON), string(resJSON), source, onDemand); err != nil {
		return fmt.Errorf("insert api result: %w", err)
	}
	if _, err := tx.ExecContext(ctx, `
		UPDATE monitor_apis SET current_status = ?, status_summary = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?
	`, write.Status, write.Summary, write.MonitorID); err != nil {
		return err
	}
	if !write.OnDemand {
		if _, err := tx.ExecContext(ctx, `
			UPDATE monitor_apis
			SET latest_scheduled_result_at = UTC_TIMESTAMP()
			WHERE id = ? AND (latest_scheduled_result_at IS NULL OR latest_scheduled_result_at < UTC_TIMESTAMP())
		`, write.MonitorID); err != nil {
			return err
		}
	} else if _, err := tx.ExecContext(ctx, `UPDATE monitor_apis SET diagnostic_queued_at = NULL WHERE id = ?`, write.MonitorID); err != nil {
		return err
	}
	prev := ""
	if previous.Valid {
		prev = previous.String
	}
	if event := transition(prev, write.Status); event != "" {
		if err := insertOutbox(ctx, tx, "api_transition", write.MonitorID, event, write.Status, write.Summary); err != nil {
			return err
		}
	}
	return tx.Commit()
}

func (s *Store) SaveShadow(ctx context.Context, kind string, id int64, laravelStatus, goStatus, result, detail string) error {
	_, err := s.DB.ExecContext(ctx, `
		INSERT INTO checker_shadow_samples (kind, subject_id, laravel_status, go_status, match_result, detail, created_at)
		VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
		ON DUPLICATE KEY UPDATE
			laravel_status = VALUES(laravel_status),
			go_status = VALUES(go_status),
			match_result = VALUES(match_result),
			detail = VALUES(detail),
			created_at = UTC_TIMESTAMP(6)
	`, kind, id, laravelStatus, goStatus, result, trim(detail, 500))
	return err
}

type ShadowCandidate struct {
	ID            int64
	LaravelStatus string
	ResultAt      time.Time
}

func (s *Store) ShadowUptime(ctx context.Context) ([]ShadowCandidate, error) {
	return s.shadow(ctx, `
		SELECT w.id, h.status, h.created_at
		FROM websites w
		JOIN website_log_history h ON h.id = (
			SELECT MAX(id) FROM website_log_history WHERE website_id = w.id AND is_on_demand = 0
		)
		LEFT JOIN checker_shadow_samples s ON s.kind = 'uptime' AND s.subject_id = w.id
		WHERE w.deleted_at IS NULL AND w.uptime_check = 1
			AND h.created_at >= UTC_TIMESTAMP() - INTERVAL 20 MINUTE
			AND (s.id IS NULL OR s.created_at < h.created_at)
		LIMIT 40
	`)
}

func (s *Store) ShadowSSL(ctx context.Context) ([]ShadowCandidate, error) {
	return s.shadow(ctx, `
		SELECT w.id, h.status, h.created_at
		FROM websites w
		JOIN website_log_history h ON h.id = (
			SELECT MAX(id) FROM website_log_history WHERE website_id = w.id AND is_on_demand = 0 AND http_status_code IS NULL
		)
		LEFT JOIN checker_shadow_samples s ON s.kind = 'ssl' AND s.subject_id = w.id
		WHERE w.deleted_at IS NULL AND w.ssl_check = 1 AND w.uptime_check = 0
			AND h.created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY
			AND (s.id IS NULL OR s.created_at < h.created_at)
		LIMIT 40
	`)
}

func (s *Store) ShadowAPI(ctx context.Context) ([]ShadowCandidate, error) {
	return s.shadow(ctx, `
		SELECT a.id, r.status, r.created_at
		FROM monitor_apis a
		JOIN monitor_api_results r ON r.id = (
			SELECT MAX(id) FROM monitor_api_results WHERE monitor_api_id = a.id AND is_on_demand = 0
		)
		LEFT JOIN checker_shadow_samples s ON s.kind = 'api' AND s.subject_id = a.id
		WHERE a.deleted_at IS NULL AND a.is_enabled = 1
			AND r.created_at >= UTC_TIMESTAMP() - INTERVAL 20 MINUTE
			AND (s.id IS NULL OR s.created_at < r.created_at)
		LIMIT 40
	`)
}

func (s *Store) shadow(ctx context.Context, query string) ([]ShadowCandidate, error) {
	rows, err := s.DB.QueryContext(ctx, query)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []ShadowCandidate
	for rows.Next() {
		var item ShadowCandidate
		var state sql.NullString
		if err := rows.Scan(&item.ID, &state, &item.ResultAt); err != nil {
			return nil, err
		}
		if state.Valid {
			item.LaravelStatus = state.String
		}
		out = append(out, item)
	}
	return out, rows.Err()
}

func insertOutbox(ctx context.Context, tx *sql.Tx, kind string, id int64, event, state, summary string) error {
	_, err := tx.ExecContext(ctx, `
		INSERT INTO checker_outbox (kind, subject_id, event, status, summary, attempts, created_at)
		VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), 0, UTC_TIMESTAMP(6))
	`, kind, id, event, state, summary)
	return err
}

func insertOutboxOnce(ctx context.Context, tx *sql.Tx, kind string, id int64, event, state, summary string) error {
	var n int
	if err := tx.QueryRowContext(ctx, `
		SELECT COUNT(*) FROM checker_outbox WHERE kind = ? AND subject_id = ? AND processed_at IS NULL
	`, kind, id).Scan(&n); err != nil {
		return err
	}
	if n > 0 {
		return nil
	}
	return insertOutbox(ctx, tx, kind, id, event, state, summary)
}

func transition(previous, next string) string {
	if (next == "warning" || next == "danger") && previous != next {
		return "heartbeat"
	}
	if next == "healthy" && (previous == "warning" || previous == "danger") {
		return "recovered"
	}
	return ""
}

func expiryDayChanged(previous sql.NullString, next time.Time) bool {
	if !previous.Valid || previous.String == "" {
		return true
	}
	parsed, err := time.Parse("2006-01-02 15:04:05", previous.String)
	if err != nil {
		parsed, err = time.Parse("2006-01-02", previous.String)
		if err != nil {
			return true
		}
	}
	ny, nm, nd := next.UTC().Date()
	py, pm, pd := parsed.UTC().Date()
	return ny != py || nm != pm || nd != pd
}

func nullString(value sql.NullString) any {
	if !value.Valid {
		return nil
	}
	return value.String
}

func trim(value string, max int) string {
	runes := []rune(value)
	if len(runes) <= max {
		return value
	}
	return string(runes[:max])
}

func capValue(value any) any {
	switch typed := value.(type) {
	case string:
		return trim(typed, 1000)
	case nil, bool, int, float64:
		return value
	default:
		encoded, err := json.Marshal(value)
		if err != nil {
			return "[unserializable value]"
		}
		return trim(string(encoded), 1000)
	}
}

func redact(value any) any {
	switch typed := value.(type) {
	case map[string]any:
		out := map[string]any{}
		for key, item := range typed {
			if sensitiveKey(key) {
				out[key] = "[redacted]"
				continue
			}
			out[key] = redact(item)
		}
		return out
	case []any:
		out := make([]any, len(typed))
		for i, item := range typed {
			out[i] = redact(item)
		}
		return out
	case string:
		return trim(typed, 4096)
	default:
		return value
	}
}

func sensitiveKey(name string) bool {
	compact := strings.ReplaceAll(strings.ReplaceAll(strings.ReplaceAll(strings.ToLower(name), "-", ""), "_", ""), " ", "")
	return strings.Contains(compact, "authorization") || strings.Contains(compact, "token") ||
		strings.Contains(compact, "secret") || strings.Contains(compact, "apikey") ||
		strings.Contains(compact, "authkey") || strings.Contains(compact, "signature") ||
		strings.Contains(compact, "cookie") || strings.Contains(compact, "password")
}

// MaskHeaders redacts secrets before they are stored on a result row.
func MaskHeaders(headers map[string]string) map[string]string {
	out := map[string]string{}
	for key, value := range headers {
		if sensitiveKey(key) {
			out[key] = "[redacted]"
			continue
		}
		out[key] = value
	}
	return out
}
