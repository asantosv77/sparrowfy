#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html/audio
umask 0007
python3 /opt/sparrowfy/init_db.py
mkdir -p data/{albums,cron-control,container-memory,mornye-control} /sparrowfy/{hls_out,inbox}
if [[ "${SPARROWFY_CRON_ENABLED:-0}" == 1 ]]; then
    (while true; do python3 scripts/sparrowfy_cron.py; sleep 60; done) &
else
    /usr/local/bin/sparrowfy-processing &
fi
exec apache2-foreground
