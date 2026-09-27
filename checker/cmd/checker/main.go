package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"runtime/debug"
	"strconv"
	"syscall"
	"time"

	"github.com/Marin-Solutions/checkybot/checker/internal/crypt"
	"github.com/Marin-Solutions/checkybot/checker/internal/run"
	"github.com/Marin-Solutions/checkybot/checker/internal/store"
)

func main() {
	log := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	if err := serve(log); err != nil {
		log.Error("checker stopped", "error", err.Error())
		os.Exit(1)
	}
}

func serve(log *slog.Logger) error {
	if raw := os.Getenv("GOMEMLIMIT"); raw != "" {
		if limit, err := parseBytes(raw); err == nil {
			debug.SetMemoryLimit(limit)
		}
	}
	dsn, err := dsnFromEnv()
	if err != nil {
		return err
	}
	concurrency := envInt("CHECKER_CONCURRENCY", 8)
	db, err := store.Open(dsn, concurrency+4)
	if err != nil {
		return err
	}
	defer db.Close()
	if err := db.EnsureReady(context.Background()); err != nil {
		return err
	}
	location, err := time.LoadLocation(env("APP_TIMEZONE", "UTC"))
	if err != nil {
		return fmt.Errorf("timezone: %w", err)
	}
	runner := &run.Runner{
		Store: db, Location: location, Concurrency: concurrency,
		RetryDelay: time.Second, Log: log,
	}
	if key, err := crypt.ParseKey(os.Getenv("APP_KEY")); err == nil {
		runner.Key = key
		runner.HasKey = true
	} else if os.Getenv("APP_KEY") != "" {
		return err
	}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	healthErr := make(chan error, 1)
	go func() {
		healthErr <- listenHealth(ctx, log, db)
	}()
	log.Info("checker started", "concurrency", concurrency)
	ticker := time.NewTicker(10 * time.Second)
	defer ticker.Stop()
	runOnce := func() {
		if err := runner.Tick(ctx); err != nil && !errors.Is(err, context.Canceled) {
			log.Error("tick failed", "error", err.Error())
		}
	}
	runOnce()
	for {
		select {
		case <-ctx.Done():
			log.Info("checker stopping")
			return nil
		case err := <-healthErr:
			if err != nil && !errors.Is(err, http.ErrServerClosed) {
				return err
			}
		case <-ticker.C:
			runOnce()
		}
	}
}

func listenHealth(ctx context.Context, log *slog.Logger, db *store.Store) error {
	addr := env("CHECKER_HEALTH_ADDR", "127.0.0.1:8097")
	srv := &http.Server{
		Addr: addr,
		Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			settings, err := db.Settings(r.Context())
			if err != nil {
				http.Error(w, "not ready", http.StatusServiceUnavailable)
				return
			}
			fmt.Fprintf(w, "ok mode=%s uptime=%s ssl=%s api=%s\n",
				settings["process_mode"], settings["uptime_owner"], settings["ssl_owner"], settings["api_owner"])
		}),
		ReadHeaderTimeout: 2 * time.Second,
	}
	go func() {
		<-ctx.Done()
		shut, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()
		_ = srv.Shutdown(shut)
	}()
	log.Info("health listening", "addr", addr)
	return srv.ListenAndServe()
}

func dsnFromEnv() (string, error) {
	if dsn := os.Getenv("CHECKER_DSN"); dsn != "" {
		return dsn, nil
	}
	user := os.Getenv("DB_USERNAME")
	name := os.Getenv("DB_DATABASE")
	if user == "" || name == "" {
		return "", errors.New("DB_USERNAME and DB_DATABASE are required")
	}
	host := env("DB_HOST", "127.0.0.1")
	port := env("DB_PORT", "3306")
	return fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true&loc=UTC&charset=utf8mb4&collation=utf8mb4_unicode_ci&multiStatements=true",
		url.QueryEscape(user), url.QueryEscape(os.Getenv("DB_PASSWORD")), host, port, name), nil
}

func env(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return fallback
}

func envInt(key string, fallback int) int {
	raw := os.Getenv(key)
	if raw == "" {
		return fallback
	}
	value, err := strconv.Atoi(raw)
	if err != nil || value < 1 {
		return fallback
	}
	return value
}

func parseBytes(raw string) (int64, error) {
	factor := int64(1)
	switch {
	case len(raw) > 3 && (raw[len(raw)-3:] == "MiB" || raw[len(raw)-3:] == "mib"):
		factor = 1 << 20
		raw = raw[:len(raw)-3]
	case len(raw) > 2 && (raw[len(raw)-2:] == "MB" || raw[len(raw)-2:] == "mb"):
		factor = 1000 * 1000
		raw = raw[:len(raw)-2]
	}
	value, err := strconv.ParseInt(raw, 10, 64)
	if err != nil {
		return 0, err
	}
	return value * factor, nil
}
