# DevOps

This document describes the production and staging infrastructure for CheckyBot as seen through Ploi MCP on 2026-04-29.

Keep this file up to date when Ploi infrastructure, runtime versions, queues, or databases change.

## Ploi Server

- Ploi name: `checkybot-main`
- Ploi server id: `79201`
- Server status: `active`
- Server type: `server`
- Network details: available through Ploi when needed.
- System user for sites: `ploi`
- Monitoring: enabled
- OPcache: enabled

## Websites

### Production

- URL: `https://checkybot.com`
- Ploi site id: `244469`
- Ploi server id: `79201`
- Project type: `laravel`
- Project root: `/`
- Web directory: `/public`
- System user: `ploi`
- PHP version: `8.3`
- Repository connected in Ploi: yes
- Zero downtime deployment: disabled
- Ploi staging feature: disabled
- FastCGI cache: disabled

### Staging

- URL: `https://staging.checkybot.com`
- Ploi site id: `313325`
- Ploi server id: `79201`
- Project type: `laravel`
- Project root: `/`
- Web directory: `/public`
- System user: `ploi`
- PHP version: `8.3`
- Repository connected in Ploi: yes
- Zero downtime deployment: disabled
- Ploi staging feature: disabled
- FastCGI cache: disabled

## Architecture

- Runtime: Laravel application, currently requiring PHP `^8.3`.
- Server PHP version: `8.3`.
- Server PHP CLI version: `8.3`.
- Installed PHP versions on the server: `8.3`.
- Webserver: Ploi-managed Nginx with PHP-FPM.
- Database engine: MySQL.
- Server MySQL version: `8`.
- Queue backend: Redis, configured by default in `config/queue.php`.
- Queue manager: Laravel Horizon.
- Realtime broadcasting: Laravel Reverb is present and configured through environment variables.
- Frontend build: Vite, built with `npm run build`.

## Databases

### Production Database

- Ploi database id: `199162`
- Name: `checkybot`
- Type: `mysql`
- Status: `active`
- Server id: `79201`

Use read-only production database access for inspection or exports when possible. Do not run destructive operations against production manually.

### Staging Database

- Ploi database id: `249720`
- Name: `staging`
- Type: `mysql`
- Status: `active`
- Server id: `79201`

## Queue Workers

Horizon is configured in `config/horizon.php` with Redis-backed supervisors:

- `supervisor-1`: queues `default` and `ssl-check`, up to 3 processes in production.
- `supervisor-2`: queue `log-website`, up to 3 processes in production.

The Ploi deploy scripts restart queue processing with:

- `php artisan queue:restart`
- `php artisan horizon:terminate`

## Deployment Commands In Ploi

Both Ploi sites deploy the Laravel app by pulling from `origin master`, installing Composer and npm dependencies, building frontend assets, caching framework metadata, running migrations with `--force`, pruning Telescope data, and restarting queue/Horizon workers.

The Ploi webhook URLs are intentionally not stored in this repository. Use the Ploi MCP site ids in `DEPLOY.md` instead.

## Additional Infrastructure

- No load balancer is attached to this project in Ploi.
- No separate webserver is attached to this project in Ploi.
- No external database server is attached to this project in Ploi; the production and staging MySQL databases are on `checkybot-main`.
