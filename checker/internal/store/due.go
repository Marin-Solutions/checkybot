package store

// Minute-truncated uptime due-ness lives in DueUptime. These statements match
// the MySQL branches of CheckApiMonitors and WriteJobCheckSsl.

const intervalMinutesSQL = `
case
    when package_interval regexp '^0*[1-9][0-9]*[smhd]$' and right(package_interval, 1) = 's' then ceiling(cast(substr(package_interval, 1, char_length(package_interval) - 1) as unsigned) / 60)
    when package_interval regexp '^0*[1-9][0-9]*[smhd]$' and right(package_interval, 1) = 'm' then cast(substr(package_interval, 1, char_length(package_interval) - 1) as unsigned)
    when package_interval regexp '^0*[1-9][0-9]*[smhd]$' and right(package_interval, 1) = 'h' then cast(substr(package_interval, 1, char_length(package_interval) - 1) as unsigned) * 60
    when package_interval regexp '^0*[1-9][0-9]*[smhd]$' and right(package_interval, 1) = 'd' then cast(substr(package_interval, 1, char_length(package_interval) - 1) as unsigned) * 1440
    when substring(package_interval, char_length(concat('every_', substring_index(substring(package_interval, 7), '_', 1), '_')) + 1) in ('second', 'seconds') then ceiling(cast(substring_index(substring(package_interval, 7), '_', 1) as unsigned) / 60)
    when substring(package_interval, char_length(concat('every_', substring_index(substring(package_interval, 7), '_', 1), '_')) + 1) in ('minute', 'minutes') then cast(substring_index(substring(package_interval, 7), '_', 1) as unsigned)
    when substring(package_interval, char_length(concat('every_', substring_index(substring(package_interval, 7), '_', 1), '_')) + 1) in ('hour', 'hours') then cast(substring_index(substring(package_interval, 7), '_', 1) as unsigned) * 60
    when substring(package_interval, char_length(concat('every_', substring_index(substring(package_interval, 7), '_', 1), '_')) + 1) in ('day', 'days') then cast(substring_index(substring(package_interval, 7), '_', 1) as unsigned) * 1440
end`

const validIntervalSQL = `(package_interval regexp '^0*[1-9][0-9]*[smhd]$' or package_interval regexp '^every_0*[1-9][0-9]*_(second|seconds|minute|minutes|hour|hours|day|days)$')`

const packageDueSQL = `
(
    package_interval REGEXP '^[0-9]*[1-9][0-9]*[smhd]$'
    AND CHAR_LENGTH(package_interval) - 1 <= 6
    AND TIMESTAMPADD(MINUTE, CASE RIGHT(package_interval, 1)
        WHEN 's' THEN FLOOR((CAST(SUBSTRING(package_interval, 1, CHAR_LENGTH(package_interval) - 1) AS UNSIGNED) + 59) / 60)
        WHEN 'm' THEN CAST(SUBSTRING(package_interval, 1, CHAR_LENGTH(package_interval) - 1) AS UNSIGNED)
        WHEN 'h' THEN CAST(SUBSTRING(package_interval, 1, CHAR_LENGTH(package_interval) - 1) AS UNSIGNED) * 60
        WHEN 'd' THEN CAST(SUBSTRING(package_interval, 1, CHAR_LENGTH(package_interval) - 1) AS UNSIGNED) * 1440
    END, websites.latest_scheduled_result_at) <= ?
) OR (
    package_interval REGEXP '^every_[0-9]*[1-9][0-9]*_(second|seconds|minute|minutes|hour|hours|day|days)$'
    AND CHAR_LENGTH(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1)) <= 6
    AND TIMESTAMPADD(MINUTE, CASE SUBSTRING_INDEX(package_interval, '_', -1)
        WHEN 'second' THEN FLOOR((CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) + 59) / 60)
        WHEN 'seconds' THEN FLOOR((CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) + 59) / 60)
        WHEN 'minute' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED)
        WHEN 'minutes' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED)
        WHEN 'hour' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) * 60
        WHEN 'hours' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) * 60
        WHEN 'day' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) * 1440
        WHEN 'days' THEN CAST(SUBSTRING_INDEX(SUBSTRING(package_interval, 7), '_', 1) AS UNSIGNED) * 1440
    END, websites.latest_scheduled_result_at) <= ?
)`

const reminderSQL = `
ssl_expiry_date IS NULL
OR DATE(ssl_expiry_date) < CURDATE()
OR (DATE(ssl_expiry_date) >= CURDATE() + INTERVAL 14 DAY AND DATE(ssl_expiry_date) < CURDATE() + INTERVAL 15 DAY)
OR (DATE(ssl_expiry_date) >= CURDATE() + INTERVAL 7 DAY AND DATE(ssl_expiry_date) < CURDATE() + INTERVAL 8 DAY)
OR (DATE(ssl_expiry_date) >= CURDATE() + INTERVAL 3 DAY AND DATE(ssl_expiry_date) < CURDATE() + INTERVAL 4 DAY)
OR (DATE(ssl_expiry_date) >= CURDATE() + INTERVAL 2 DAY AND DATE(ssl_expiry_date) < CURDATE() + INTERVAL 3 DAY)
OR (DATE(ssl_expiry_date) >= CURDATE() + INTERVAL 1 DAY AND DATE(ssl_expiry_date) < CURDATE() + INTERVAL 2 DAY)
OR (DATE(ssl_expiry_date) >= CURDATE() AND DATE(ssl_expiry_date) < CURDATE() + INTERVAL 1 DAY)
`

const duePredicateSQL = `
(
    source = 'package' AND uptime_check = 0 AND package_interval IS NOT NULL AND package_interval <> ''
    AND (latest_scheduled_result_at IS NULL OR ` + packageDueSQL + `)
) OR (
    (source <> 'package' OR source IS NULL) AND uptime_check = 0 AND uptime_interval IS NOT NULL
    AND (
        latest_scheduled_result_at IS NULL
        OR DATE_ADD(DATE_FORMAT(latest_scheduled_result_at, '%Y-%m-%d %H:%i:00'), INTERVAL uptime_interval MINUTE) <= ?
    )
) OR (
    (
        uptime_check = 1
        OR (source = 'package' AND (package_interval IS NULL OR package_interval = ''))
        OR ((source <> 'package' OR source IS NULL) AND uptime_interval IS NULL)
    )
    AND (` + reminderSQL + `)
)`

const dueSSLSQL = `
SELECT id FROM websites
WHERE deleted_at IS NULL AND ssl_check = 1 AND (` + duePredicateSQL + `)`

const dueSSLSQLForID = `
SELECT COUNT(*) FROM websites
WHERE id = ? AND deleted_at IS NULL AND ssl_check = 1 AND (` + duePredicateSQL + `)`

const dueAPISQL = `
SELECT id FROM monitor_apis
WHERE deleted_at IS NULL AND is_enabled = 1 AND (
    package_interval IS NULL OR package_interval = ''
    OR latest_scheduled_result_at IS NULL
    OR NOT ` + validIntervalSQL + `
    OR (
        ` + validIntervalSQL + `
        AND DATE_ADD(DATE_FORMAT(latest_scheduled_result_at, '%Y-%m-%d %H:%i:00'), INTERVAL (` + intervalMinutesSQL + `) MINUTE)
            <= DATE_FORMAT(?, '%Y-%m-%d %H:%i:00')
    )
)`

const dueAPISQLForID = `
SELECT COUNT(*) FROM monitor_apis
WHERE id = ? AND deleted_at IS NULL AND is_enabled = 1 AND (
    package_interval IS NULL OR package_interval = ''
    OR latest_scheduled_result_at IS NULL
    OR NOT ` + validIntervalSQL + `
    OR (
        ` + validIntervalSQL + `
        AND DATE_ADD(DATE_FORMAT(latest_scheduled_result_at, '%Y-%m-%d %H:%i:00'), INTERVAL (` + intervalMinutesSQL + `) MINUTE)
            <= DATE_FORMAT(?, '%Y-%m-%d %H:%i:00')
    )
)`
