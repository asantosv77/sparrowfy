#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ -n "$(docker compose ps --status running -q www)" ]]; then
    bash scripts/backup.sh
fi
docker compose build
docker compose up -d --wait --wait-timeout 120
port=$(docker compose port www 8080)
BASE_URL="http://127.0.0.1:${port##*:}" bash scripts/smoke-test.sh
