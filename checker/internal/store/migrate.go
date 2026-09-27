package store

import (
	"context"
	"fmt"

	_ "embed"
)

//go:embed schema.sql
var schemaSQL string

// Migrate creates the checker tables and the slim tables the tests write.
func (s *Store) Migrate(ctx context.Context) error {
	if _, err := s.DB.ExecContext(ctx, schemaSQL); err != nil {
		return fmt.Errorf("migrate checker schema: %w", err)
	}
	return s.seed(ctx)
}

// EnsureReady checks that the Laravel migration has already created the coordination tables.
// It does not create application tables.
func (s *Store) EnsureReady(ctx context.Context) error {
	var n int
	if err := s.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM checker_settings`).Scan(&n); err != nil {
		return fmt.Errorf("checker tables are missing; deploy the Laravel migration before starting the checker: %w", err)
	}
	if n == 0 {
		return s.seed(ctx)
	}
	return nil
}

func (s *Store) seed(ctx context.Context) error {
	_, err := s.DB.ExecContext(ctx, `
		INSERT INTO checker_settings (setting_key, setting_value, updated_at)
		VALUES ('process_mode', 'shadow', UTC_TIMESTAMP()),
			('uptime_owner', 'laravel', UTC_TIMESTAMP()),
			('ssl_owner', 'laravel', UTC_TIMESTAMP()),
			('api_owner', 'laravel', UTC_TIMESTAMP())
		ON DUPLICATE KEY UPDATE setting_key = setting_key
	`)
	if err != nil {
		return fmt.Errorf("seed checker settings: %w", err)
	}
	return nil
}
