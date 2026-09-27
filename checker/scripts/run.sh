#!/usr/bin/env bash
# Ploi daemon command: bash checker/scripts/run.sh
# Working directory is the Laravel site root. checker.env sits beside it and is not in git.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="${CHECKER_ENV_FILE:-$ROOT/checker.env}"
set -a
# shellcheck disable=SC1090
. "$ENV_FILE"
set +a
exec "$ROOT/checker/bin/checker"
