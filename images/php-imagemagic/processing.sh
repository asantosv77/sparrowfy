#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html/audio
exec flock -n data/cron-control/processing-loop.lock bash -c '
    while true; do
        python3 scripts/sparrowfy_cron.py --task processing
        sleep 15
    done
'
