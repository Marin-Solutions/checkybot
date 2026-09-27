#!/usr/bin/env bash
# Read-only baseline for the check queues.
# Usage: CHECKER_SSH=ploi@your-checkybot-host bash checker/bench/measure.sh
# Prints aggregates only. Does not print .env values, URLs, or row contents.
set -euo pipefail

if [[ -z "${CHECKER_SSH:-}" ]]; then
  echo "Set CHECKER_SSH to the ploi user on the checkybot host." >&2
  exit 1
fi

ssh -o BatchMode=yes -o ConnectTimeout=10 "$CHECKER_SSH" 'bash -s' << 'REMOTE'
set -euo pipefail
python3 - << 'PY'
import os, subprocess
from pathlib import Path
env = {}
for raw in Path("/home/ploi/checkybot.com/.env").read_text(errors="replace").splitlines():
    line = raw.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    k, v = line.split("=", 1)
    env[k.strip()] = v.strip().strip('"').strip("'")
cnf = Path("/tmp/checker-bench.cnf")
cnf.write_text("[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n" % (
    env.get("DB_USERNAME", "ploi"),
    env.get("DB_PASSWORD", ""),
    env.get("DB_HOST", "127.0.0.1"),
    env.get("DB_PORT", "3306"),
))
os.chmod(cnf, 0o600)
db = env.get("DB_DATABASE", "")
secret = env.get("DB_PASSWORD", "")
def q(sql):
    r = subprocess.run(["mysql", "--defaults-extra-file="+str(cnf), "--batch", "--raw", "-N", db, "-e", sql], capture_output=True, text=True)
    if r.returncode != 0:
        err = r.stderr.replace(secret, "***")
        raise SystemExit("mysql failed: " + err[-400:])
    return r.stdout.strip()
def pct(values, p):
    if not values:
        return "na"
    s = sorted(values)
    i = min(len(s) - 1, max(0, int(round((p / 100) * (len(s) - 1)))))
    return str(s[i])
print("timezone", env.get("APP_TIMEZONE", "UTC"))
print(q("""
SELECT CONCAT('websites_uptime ', COUNT(*)) FROM websites WHERE deleted_at IS NULL AND uptime_check=1
UNION ALL SELECT CONCAT('websites_ssl_only ', COUNT(*)) FROM websites WHERE deleted_at IS NULL AND ssl_check=1 AND (uptime_check=0 OR uptime_check IS NULL)
UNION ALL SELECT CONCAT('apis_enabled ', COUNT(*)) FROM monitor_apis WHERE deleted_at IS NULL AND is_enabled=1
"""))
print("uptime_60m", q("""
SELECT CONCAT('rows ', COUNT(*), ' danger ', COALESCE(SUM(status='danger'),0), ' warning ', COALESCE(SUM(status='warning'),0), ' healthy ', COALESCE(SUM(status='healthy'),0), ' transport0 ', COALESCE(SUM(http_status_code=0),0))
FROM website_log_history WHERE created_at >= NOW() - INTERVAL 60 MINUTE AND is_on_demand=0
"""))
print("api_60m", q("""
SELECT CONCAT('rows ', COUNT(*), ' danger ', COALESCE(SUM(status='danger'),0), ' warning ', COALESCE(SUM(status='warning'),0), ' healthy ', COALESCE(SUM(status='healthy'),0), ' code0 ', COALESCE(SUM(http_code=0),0))
FROM monitor_api_results WHERE created_at >= NOW() - INTERVAL 60 MINUTE AND is_on_demand=0
"""))
print("uptime_per_min", q("""
SELECT CONCAT('minutes ', COUNT(*), ' min ', MIN(c), ' max ', MAX(c), ' total ', SUM(c)) FROM (
  SELECT COUNT(*) c FROM website_log_history
  WHERE created_at >= NOW() - INTERVAL 60 MINUTE AND is_on_demand=0
  GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H:%i')
) t
"""))
print("api_per_min", q("""
SELECT CONCAT('minutes ', COUNT(*), ' min ', MIN(c), ' max ', MAX(c), ' total ', SUM(c)) FROM (
  SELECT COUNT(*) c FROM monitor_api_results
  WHERE created_at >= NOW() - INTERVAL 60 MINUTE AND is_on_demand=0
  GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H:%i')
) t
"""))
def lags(sql):
    raw = q(sql)
    return [int(x) for x in raw.split() if x.strip().lstrip('-').isdigit()]
ul = lags("""
SELECT lag_s FROM (
  SELECT TIMESTAMPDIFF(SECOND, DATE_ADD(DATE_FORMAT(prev_created, '%Y-%m-%d %H:%i:00'), INTERVAL uptime_interval MINUTE), created_at) lag_s
  FROM (
    SELECT h.created_at, w.uptime_interval, LAG(h.created_at) OVER (PARTITION BY h.website_id ORDER BY h.id) prev_created
    FROM website_log_history h JOIN websites w ON w.id=h.website_id
    WHERE h.created_at >= NOW() - INTERVAL 60 MINUTE AND h.is_on_demand=0 AND w.uptime_check=1
  ) x WHERE prev_created IS NOT NULL
) y WHERE lag_s BETWEEN -120 AND 1800
""")
al = lags("""
SELECT lag_s FROM (
  SELECT TIMESTAMPDIFF(SECOND, DATE_ADD(DATE_FORMAT(prev_created, '%Y-%m-%d %H:%i:00'), INTERVAL
    CASE
      WHEN package_interval REGEXP '^[0-9]+m$' THEN CAST(SUBSTRING(package_interval,1,CHAR_LENGTH(package_interval)-1) AS UNSIGNED)
      WHEN package_interval REGEXP '^[0-9]+h$' THEN CAST(SUBSTRING(package_interval,1,CHAR_LENGTH(package_interval)-1) AS UNSIGNED)*60
      WHEN package_interval REGEXP '^[0-9]+d$' THEN CAST(SUBSTRING(package_interval,1,CHAR_LENGTH(package_interval)-1) AS UNSIGNED)*1440
      WHEN package_interval REGEXP '^[0-9]+s$' THEN CEILING(CAST(SUBSTRING(package_interval,1,CHAR_LENGTH(package_interval)-1) AS UNSIGNED)/60)
      ELSE 5
    END MINUTE), created_at) lag_s
  FROM (
    SELECT r.created_at, a.package_interval, LAG(r.created_at) OVER (PARTITION BY r.monitor_api_id ORDER BY r.id) prev_created
    FROM monitor_api_results r JOIN monitor_apis a ON a.id=r.monitor_api_id
    WHERE r.created_at >= NOW() - INTERVAL 60 MINUTE AND r.is_on_demand=0 AND a.is_enabled=1
  ) x WHERE prev_created IS NOT NULL
) y WHERE lag_s BETWEEN -120 AND 3600
""")
print("uptime_lag_n", len(ul), "p50", pct(ul, 50), "p95", pct(ul, 95))
print("api_lag_n", len(al), "p50", pct(al, 50), "p95", pct(al, 95))
print("uptime_overdue", q("""
SELECT COUNT(*) FROM websites
WHERE deleted_at IS NULL AND uptime_check=1 AND uptime_interval IN (1,5,10,15,30,60,360,720,1440)
AND (latest_scheduled_result_at IS NULL OR DATE_ADD(DATE_FORMAT(latest_scheduled_result_at,'%Y-%m-%d %H:%i:00'), INTERVAL uptime_interval MINUTE) <= DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:00'))
"""))
cnf.unlink(missing_ok=True)
PY
echo "---mem---"
free -m | awk 'NR==2 {printf "mem_total_mb %s used_mb %s free_mb %s available_mb %s\n", $2, $3, $4, $7}'
echo "---procs---"
ps -eo rss,pcpu,args | awk '
  /artisan horizon:work/ && !/awk/ {wrss+=$1; wcpu+=$2; wn++}
  /artisan horizon/ && !/awk/ {hrss+=$1; hcpu+=$2; hn++}
  /php-fpm/ && !/awk/ {frss+=$1; fcpu+=$2; fn++}
  END {
    printf "horizon_all n %d rss_kib %d cpu %.1f\n", hn, hrss, hcpu
    printf "horizon_workers n %d rss_kib %d cpu %.1f\n", wn, wrss, wcpu
    printf "php_fpm n %d rss_kib %d cpu %.1f\n", fn, frss, fcpu
  }'
if pgrep -f '/checker/bin/checker' >/dev/null; then
  ps -eo rss,pcpu,args | awk '/checker\/bin\/checker/ && !/awk/ {rss+=$1; cpu+=$2; n++} END {printf "checker n %d rss_kib %d cpu %.1f\n", n, rss, cpu}'
else
  echo "checker n 0"
fi
php -r '
require "/home/ploi/checkybot.com/vendor/autoload.php";
$app=require "/home/ploi/checkybot.com/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (["default","ssl-check","log-website","api-monitor"] as $name) {
  try { echo "queue $name ".Illuminate\Support\Facades\Redis::llen("queues:".$name)."\n"; }
  catch (Throwable $e) { echo "queue $name err\n"; }
}
' 2>/dev/null || echo "queue query failed"
REMOTE
