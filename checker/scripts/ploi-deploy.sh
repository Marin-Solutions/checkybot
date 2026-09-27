#!/usr/bin/env bash
# Build the checker binary from the Laravel site checkout and restart it.
# Run on the server, from the site root:
#   bash checker/scripts/ploi-deploy.sh
# The script never prints .env values. It writes checker.env once, mode 600,
# and leaves an existing checker.env alone.
set -euo pipefail

SITE_DIR="${SITE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
ENV_FILE="${CHECKER_ENV_FILE:-$SITE_DIR/checker.env}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:8097/}"

main() {
    cd "$SITE_DIR"
    setup_go
    ensure_env
    echo "==> Building checker"
    mkdir -p checker/bin
    (
        cd checker
        go build -trimpath -ldflags "-s -w" -o bin/checker.new ./cmd/checker
    )
    mv -f checker/bin/checker.new checker/bin/checker
    chmod 755 checker/bin/checker
    restart_checker
    wait_until_healthy
    echo "==> Checker binary updated"
}

setup_go() {
    local dir
    for dir in "${GO_BIN_DIR:-}" "$HOME/.local/go/bin" /usr/local/go/bin; do
        if [ -n "$dir" ] && [ -x "$dir/go" ]; then
            export PATH="$dir:$PATH"
            echo "==> Using $(go version)"
            return 0
        fi
    done
    echo "Go is not installed. Put it in \$HOME/.local/go." >&2
    return 1
}

ensure_env() {
    if [ -f "$ENV_FILE" ]; then
        return 0
    fi
    if [ ! -f "$SITE_DIR/.env" ]; then
        echo "No Laravel .env and no $ENV_FILE" >&2
        return 1
    fi
    python3 - "$SITE_DIR/.env" "$ENV_FILE" << 'PY'
import sys
from pathlib import Path
src, dest = Path(sys.argv[1]), Path(sys.argv[2])
wanted = {"DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD", "APP_KEY", "APP_TIMEZONE"}
vals = {}
for raw in src.read_text(errors="replace").splitlines():
    line = raw.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    key, value = line.split("=", 1)
    key = key.strip()
    if key in wanted:
        vals[key] = value.strip().strip('"').strip("'")
missing = {"DB_DATABASE", "DB_USERNAME", "APP_KEY"} - vals.keys()
if missing:
    sys.stderr.write("Laravel .env is missing checker settings\n")
    sys.exit(1)
lines = [f"{key}={vals[key]}" for key in ("DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD", "APP_KEY", "APP_TIMEZONE") if key in vals]
lines += ["CHECKER_CONCURRENCY=8", "GOMEMLIMIT=256MiB", "CHECKER_HEALTH_ADDR=127.0.0.1:8097"]
dest.write_text("\n".join(lines) + "\n")
PY
    chmod 600 "$ENV_FILE"
    echo "==> Wrote checker env file"
}

restart_checker() {
    if [ -n "${RESTART_WITH_SUPERVISORCTL:-}" ]; then
        echo "==> Restarting ${RESTART_WITH_SUPERVISORCTL}"
        echo "" | sudo -S supervisorctl restart "${RESTART_WITH_SUPERVISORCTL}"
        return 0
    fi
    local pids
    pids="$(pgrep -f "$SITE_DIR/checker/bin/checker" || true)"
    if [ -z "$pids" ]; then
        echo "==> Checker is not running yet. Start the Ploi daemon when the env file is in place."
        return 0
    fi
    echo "==> Stopping the running checker so the daemon starts the new binary"
    # shellcheck disable=SC2086
    kill $pids || true
}

wait_until_healthy() {
    local i
    for i in 1 2 3 4 5 6 7 8 9 10; do
        if curl -fsS --max-time 2 "$HEALTH_URL" >/dev/null 2>&1; then
            echo "==> Health check ok"
            return 0
        fi
        sleep 1
    done
    echo "==> Health check did not answer. If the daemon is not installed yet, that is expected."
}

main "$@"
