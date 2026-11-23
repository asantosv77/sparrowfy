#!/bin/sh
set -eu
cd /var/www/html/audio
umask 0007
mkdir -p data/mornye-control
if [ -f data/mornye-control/worker.log ] && [ "$(wc -c < data/mornye-control/worker.log)" -gt 2097152 ]; then
 mv -f data/mornye-control/worker.log data/mornye-control/worker.log.previous
fi
php scripts/processing_worker.php >> data/mornye-control/worker.log 2>&1
