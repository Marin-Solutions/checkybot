# MySQL OOM Restart Stabilization

## Incident

At 2026-05-26 06:03:06 UTC, the kernel OOM killer killed `mysqld` on `checkybot-main` (`88.99.35.80`). Systemd restarted MySQL at 06:03:08 UTC and the service became active again at 06:03:11 UTC. Laravel scheduler work that touched the database at 06:03 saw `SQLSTATE[HY000] [2002] Connection refused`.

## Evidence

- `mysql.service` reported `Failed with result 'oom-kill'` and `NRestarts=1`.
- Kernel OOM details showed `mysqld` at roughly 9.5 GiB RSS and a PHP CLI process launched from an SSH session at roughly 4.5 GiB RSS.
- The host has 15 GiB RAM and 1 GiB swap.
- MySQL was configured with `innodb_buffer_pool_size = 8G`, leaving little headroom for PHP-FPM, Horizon workers, scheduler commands, and temporary CLI/audit processes.
- `apt-daily-upgrade` ran at 06:00 but found no packages to upgrade and was not the restart trigger.

## Production Changes

Applied on `checkybot-main` through Ploi root scripts:

- Lowered and persisted MySQL InnoDB buffer pool from 8 GiB to 5 GiB in `/etc/mysql/conf.d/90-innodb-buffer-pool.cnf`.
- Applied the same value live with `SET GLOBAL innodb_buffer_pool_size = 5368709120`, avoiding a MySQL restart.
- Added `/etc/systemd/system/mysql.service.d/10-checkybot-stability.conf` with:
  - `OnFailure=checkybot-mysql-alert@%n.service`
  - `OOMScoreAdjust=-800`
- Added `checkybot-mysql-alert@.service` and `/usr/local/sbin/checkybot-mysql-restart-alert` so unexpected MySQL failures are logged and sent through the existing Checkybot notification channel.
- Updated `/etc/crontab` so the Laravel scheduler runs as:
  - `php -d memory_limit=512M /home/ploi/checkybot.com/artisan schedule:run`
- Added `/etc/php/8.3/cli/conf.d/99-checkybot-cli-memory-limit.ini` with `memory_limit=512M` so scheduler child commands and manual/audit `php artisan ...` invocations cannot allocate multi-GiB memory and force another host OOM event.

## Verification

- `SHOW VARIABLES LIKE 'innodb_buffer_pool_size'` returned `5368709120`.
- `systemctl show mysql` returned `ActiveState=active`, `Result=success`, `OOMScoreAdjust=-800`, and the expected drop-in path.
- Alert script dry-run printed the expected MySQL restart alert.
- `bash -n /usr/local/sbin/checkybot-mysql-restart-alert` passed.
- `/etc/crontab` contains the scheduler PHP memory cap.
- `php` and `php8.3` both report `memory_limit=512M` for CLI.
- `php artisan about --only=environment` runs successfully under the new CLI limit.

## Follow-up

If memory pressure recurs, inspect PHP CLI processes first. The OOM table for this incident showed a multi-GiB PHP process from an SSH session, not normal scheduler memory use.
