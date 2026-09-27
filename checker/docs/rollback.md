# Rollback

Stop Go from writing, then let Laravel run the checks again. Do not drop tables and do not delete the paused Horizon supervisors for 48 hours.

1. On the Checkybot database:

```sql
UPDATE checker_settings SET setting_value = 'laravel', updated_at = UTC_TIMESTAMP()
WHERE setting_key IN ('uptime_owner', 'ssl_owner', 'api_owner');
UPDATE checker_settings SET setting_value = 'shadow', updated_at = UTC_TIMESTAMP()
WHERE setting_key = 'process_mode';
```

`process_mode=shadow` makes the Go process compare only. Setting it to anything else except `live` makes it idle. `laravel` owners make the Artisan commands queue the jobs again.

2. Continue the paused Horizon supervisors:

```bash
php artisan horizon:continue-supervisor supervisor-4
php artisan horizon:continue-supervisor supervisor-2
php artisan horizon:continue-supervisor supervisor-3
```

`supervisor-4` is `ssl-check`, `supervisor-2` is `log-website`, `supervisor-3` is `api-monitor`. `supervisor-1` stays on `default` and is never paused for this cutover.

3. If a wrong notification is still waiting in `checker_outbox`, mark only those unprocessed rows processed after checking them. Do not delete result history.

4. The daemon can stay installed. To stop it immediately, pause the Ploi daemon named `checkybot-checker`.

5. Confirm new rows in `website_log_history` and `monitor_api_results` within two minutes, and that the three check queues are not growing.
