// Package testdb opens a private MySQL database for one test.
package testdb

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"fmt"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/Marin-Solutions/checkybot/checker/internal/store"
	mysql "github.com/go-sql-driver/mysql"
)

// Open returns a migrated store and drops its database when the test ends.
func Open(t *testing.T) *store.Store {
	t.Helper()
	base := os.Getenv("TEST_MYSQL_DSN")
	if base == "" {
		base = "root:checker@tcp(127.0.0.1:33068)/"
	}
	cfg, err := mysql.ParseDSN(base)
	if err != nil {
		t.Fatalf("parse dsn: %v", err)
	}
	cfg.ParseTime = true
	cfg.Loc = time.UTC
	cfg.MultiStatements = true
	cfg.DBName = ""
	admin, err := sql.Open("mysql", cfg.FormatDSN())
	if err != nil {
		t.Fatalf("open admin: %v", err)
	}
	t.Cleanup(func() { _ = admin.Close() })
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	if err := admin.PingContext(ctx); err != nil {
		t.Fatalf("mysql is not reachable at %s: %v", cfg.Addr, err)
	}
	name := "checker_" + randomSuffix(t)
	if _, err := admin.ExecContext(ctx, "CREATE DATABASE `"+name+"`"); err != nil {
		t.Fatalf("create database: %v", err)
	}
	t.Cleanup(func() {
		cleanCtx, cleanCancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cleanCancel()
		_, _ = admin.ExecContext(cleanCtx, "DROP DATABASE IF EXISTS `"+name+"`")
	})
	cfg.DBName = name
	opened, err := store.Open(cfg.FormatDSN(), 8)
	if err != nil {
		t.Fatalf("open test database: %v", err)
	}
	t.Cleanup(func() { _ = opened.Close() })
	if err := opened.Migrate(ctx); err != nil {
		t.Fatalf("migrate: %v", err)
	}
	return opened
}

func randomSuffix(t *testing.T) string {
	t.Helper()
	buf := make([]byte, 6)
	if _, err := rand.Read(buf); err != nil {
		t.Fatal(err)
	}
	return strings.ToLower(hex.EncodeToString(buf))
}

// Live makes Go the owner of every check kind.
func Live(t *testing.T, db *store.Store) {
	t.Helper()
	_, err := db.DB.Exec(`UPDATE checker_settings SET setting_value = 'live' WHERE setting_key = 'process_mode'`)
	if err != nil {
		t.Fatal(err)
	}
	_, err = db.DB.Exec(`UPDATE checker_settings SET setting_value = 'go' WHERE setting_key IN ('uptime_owner', 'ssl_owner', 'api_owner')`)
	if err != nil {
		t.Fatal(err)
	}
}

func MustInsert(t *testing.T, db *store.Store, query string, args ...any) int64 {
	t.Helper()
	res, err := db.DB.Exec(query, args...)
	if err != nil {
		t.Fatalf("insert: %v\n%s", err, query)
	}
	id, err := res.LastInsertId()
	if err != nil {
		t.Fatal(err)
	}
	if id == 0 {
		t.Fatal(fmt.Errorf("insert did not return an id"))
	}
	return id
}
