# Checkybot checker

Go worker for uptime, SSL, and API checks. Laravel keeps the admin UI, the database, and notification delivery.

The process reads `checker_settings`:

| setting | live behavior |
|---|---|
| `process_mode=shadow` | Re-checks recent Laravel results and stores only `checker_shadow_samples`. No customer rows, no locks. |
| `process_mode=live` and `*_owner=go` | That check kind is claimed, executed, and written by Go. Laravel stops queueing it. |
| anything else | The process stays up and does not run checks. |

Status changes and SSL reminders are inserted into `checker_outbox`. `php artisan checker:drain-outbox` sends them through the existing Laravel mail and webhook code.

Build and test:

```bash
docker compose up -d
export PATH="$HOME/.local/go/bin:$PATH"
go test ./...
```

Production env lives in `checker.env` next to the Laravel `.env` (mode 600, not in git). See `.env.example`. The Ploi daemon runs `checker/bin/checker` with `GOMEMLIMIT=256MiB`.

Rollback is in `docs/rollback.md` after the first cutover, and in `docs/report.md`.
