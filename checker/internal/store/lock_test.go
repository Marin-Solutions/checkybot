package store_test

import (
	"context"
	"database/sql"
	"testing"
	"time"

	"github.com/Marin-Solutions/checkybot/checker/internal/testdb"
)

func TestClaimWaitsThenLosesToTheOpenInsert(t *testing.T) {
	db := testdb.Open(t)
	ctx := context.Background()
	conn, err := db.DB.Conn(ctx)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	if _, err := conn.ExecContext(ctx, `SET TRANSACTION ISOLATION LEVEL READ COMMITTED`); err != nil {
		t.Fatal(err)
	}
	tx, err := conn.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		t.Fatal(err)
	}
	defer tx.Rollback()
	if _, err := tx.ExecContext(ctx, `
		INSERT INTO checker_locks (lock_key, owner, expires_at, created_at, updated_at)
		VALUES ('race-1', 'laravel', UTC_TIMESTAMP(6) + INTERVAL 60 SECOND, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
	`); err != nil {
		t.Fatal(err)
	}

	result := make(chan bool, 1)
	failed := make(chan error, 1)
	go func() {
		ok, err := db.Claim(ctx, "race-1", "go-side", 30)
		if err != nil {
			failed <- err
			return
		}
		result <- ok
	}()

	deadline := time.Now().Add(4 * time.Second)
	waited := false
	for time.Now().Before(deadline) {
		var waiting int
		err := db.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM performance_schema.data_lock_waits`).Scan(&waiting)
		if err != nil {
			t.Fatalf("performance_schema lock wait query failed: %v", err)
		}
		if waiting > 0 {
			waited = true
			break
		}
		time.Sleep(30 * time.Millisecond)
	}
	if !waited {
		t.Fatal("the second claim did not wait on the checker_locks row")
	}
	if err := tx.Commit(); err != nil {
		t.Fatal(err)
	}
	select {
	case err := <-failed:
		t.Fatal(err)
	case ok := <-result:
		if ok {
			t.Fatal("second claim acquired a lock that was still live")
		}
	case <-time.After(8 * time.Second):
		t.Fatal("second claim did not finish after the first transaction committed")
	}
	var owner string
	if err := db.DB.QueryRowContext(ctx, `SELECT owner FROM checker_locks WHERE lock_key = 'race-1'`).Scan(&owner); err != nil {
		t.Fatal(err)
	}
	if owner != "laravel" {
		t.Fatalf("owner = %s, want laravel", owner)
	}
}

func TestExpiredLockCanBeTakenOverAndOnlyTheOwnerReleasesIt(t *testing.T) {
	db := testdb.Open(t)
	ctx := context.Background()
	if _, err := db.DB.ExecContext(ctx, `
		INSERT INTO checker_locks (lock_key, owner, expires_at, created_at, updated_at)
		VALUES ('race-2', 'old', UTC_TIMESTAMP(6) - INTERVAL 5 SECOND, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
	`); err != nil {
		t.Fatal(err)
	}
	ok, err := db.Claim(ctx, "race-2", "next", 30)
	if err != nil || !ok {
		t.Fatalf("takeover ok=%v err=%v", ok, err)
	}
	if err := db.Release(ctx, "race-2", "old"); err != nil {
		t.Fatal(err)
	}
	var owner string
	if err := db.DB.QueryRowContext(ctx, `SELECT owner FROM checker_locks WHERE lock_key = 'race-2'`).Scan(&owner); err != nil {
		t.Fatal(err)
	}
	if owner != "next" {
		t.Fatalf("owner = %s", owner)
	}
	if err := db.Release(ctx, "race-2", "next"); err != nil {
		t.Fatal(err)
	}
	var left int
	if err := db.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM checker_locks WHERE lock_key = 'race-2'`).Scan(&left); err != nil {
		t.Fatal(err)
	}
	if left != 0 {
		t.Fatalf("lock rows left = %d", left)
	}
}
