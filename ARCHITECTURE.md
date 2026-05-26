# Architecture

This document describes the host-level architecture for the CheckyBot Laravel deployment. Use `DEVOPS.md` as the deployment and operational runbook; this file is the stable architecture reference.

## Application Overview

CheckyBot is a Laravel application served from Ploi-managed sites on the `checkybot-main` server.

- Production site: `checkybot.com`
- Staging site: `staging.checkybot.com`
- Production deploy path: `/home/ploi/checkybot.com`
- Staging deploy path: `/home/ploi/staging.checkybot.com`
- Public document root: `public`
- Application user: `ploi`
- Main framework: Laravel `^12.0`
- Admin/UI stack: Filament, Livewire assets, Vite-built frontend assets

## Laravel And PHP Runtime

The repository requires PHP `^8.3`, and the Ploi server runs PHP `8.3` for both CLI and FPM.

- Composer installs production dependencies with `composer install --no-dev --prefer-dist --optimize-autoloader`.
- Frontend assets are installed with `npm install --include=dev --legacy-peer-deps` and built with `npm run build`.
- Laravel config and route caches are rebuilt during deploy.
- OPcache is enabled on the server.

## Nginx And PHP-FPM Topology

Ploi manages Nginx and PHP-FPM on the `checkybot-main` host.

- Nginx vhosts live under `/etc/nginx/sites-available` and are enabled from `/etc/nginx/sites-enabled`.
- Production Nginx root is `/home/ploi/checkybot.com/public`.
- Staging Nginx root is `/home/ploi/staging.checkybot.com/public`.
- PHP requests are passed to `unix:/run/php/php8.3-fpm.sock`.
- `nginx.service` and `php8.3-fpm.service` are systemd services.
- Site access logs are disabled in the current Nginx vhosts; site errors go to `/var/log/nginx/*checkybot.com-error.log`.

## Data Dependencies

The app uses local MySQL and Redis services on the Ploi server.

- Production database: MySQL database `checkybot`
- Staging database: MySQL database `staging`
- MySQL service: `mysql.service`
- Redis service: `redis-server.service`
- Default queue connection: Redis
- Default cache/session/database connection values are controlled by environment variables.

Redis supports queues, Horizon metadata, cache, and Reverb scaling if `REVERB_SCALING_ENABLED` is enabled.

## Queues And Horizon

Laravel Horizon is the queue manager. Ploi runs it as a daemon from:

```bash
php /home/ploi/checkybot.com/artisan horizon
```

Configured Horizon supervisors:

- `supervisor-1`: Redis queues `default` and `ssl-check`, up to 3 processes in production.
- `supervisor-2`: Redis queue `log-website`, up to 3 processes in production.

Deploys restart queue processing with:

```bash
php artisan queue:restart
php artisan horizon:terminate
```

## Scheduler

Ploi runs the Laravel scheduler every minute as the `ploi` user:

```cron
* * * * * php /home/ploi/checkybot.com/artisan schedule:run
```

The scheduler currently dispatches monitor, SSL, SEO, backup, proxy-pool, snooze-expiration, Telescope prune, and server-log purge commands from `routes/console.php`.

## Reverb And Sentry

Realtime broadcasting is configured through Laravel Reverb.

- Broadcast connection: `reverb`
- Default Reverb server host: `0.0.0.0`
- Default Reverb server port: `8080`
- Public Reverb host, port, scheme, app id, key, and secret are environment-driven.

Sentry is integrated through `sentry/sentry-laravel`.

- DSN is read from `SENTRY_LARAVEL_DSN` or `SENTRY_DSN`.
- Release and environment are read from `SENTRY_RELEASE` and `SENTRY_ENVIRONMENT`.
- Queue, command, SQL, HTTP client, cache, view, and notification tracing are controlled in `config/sentry.php`.

## Deployment Flow

Ploi deploys both production and staging by changing into the site directory and running:

```bash
bash scripts/deploy/ploi.sh
```

The deploy script:

1. Resets the working tree and pulls `origin master`.
2. Installs Composer dependencies from the committed lockfile.
3. Reinstalls frontend dependencies and builds assets.
4. Puts Laravel into maintenance mode.
5. Clears and rebuilds optimized Laravel caches.
6. Brings the app back up.
7. Runs migrations with `--force`.
8. Reloads PHP-FPM.
9. Restarts queue and Horizon workers.

Keep deployment procedures, webhook details, and operational changes in `DEVOPS.md`.

## Operational Log Locations

Primary log locations on the host:

- Laravel app logs: `/home/ploi/checkybot.com/storage/logs/`
- Staging Laravel logs: `/home/ploi/staging.checkybot.com/storage/logs/`
- Production Nginx error log: `/var/log/nginx/checkybot.com-error.log`
- Staging Nginx error log: `/var/log/nginx/staging.checkybot.com-error.log`
- PHP-FPM log: `/var/log/php8.3-fpm.log`
- MySQL logs: `/var/log/mysql/`
- Redis logs: `/var/log/redis/`
- Ploi deployment logs: available from the Ploi site deployment log view/API.

