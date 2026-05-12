# Staging Sentry DSN Remediation

Date: 2026-05-12
Target: `staging.checkybot.com`
Mimir task: `b80d92f8-137d-42e7-955d-04724e7c3b19`

## Summary

The staging deployment had the Laravel Sentry package and `config/sentry.php`
installed, but the runtime environment did not define a Sentry DSN. The
staging `.env` on server `79201` was updated with:

- `SENTRY_LARAVEL_DSN`
- `SENTRY_ENVIRONMENT=staging`

The DSN value is intentionally omitted from this repository note.

## Verification

After updating the environment, Laravel's cached configuration was rebuilt with
`php artisan config:clear` and `php artisan config:cache`, and queue workers
were signaled with `php artisan queue:restart`.

Verification confirmed:

- `config('sentry.dsn')` is configured.
- `config('sentry.environment')` is `staging`.
- Laravel configuration is cached.
- `php artisan sentry:test` sent event `52fe78c2d1324c0a97d81d1267b97ebf`.
