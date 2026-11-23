#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
umask 0077
mkdir -p volumes/backups
pending=$(mktemp volumes/backups/.backup-XXXXXXXX)
trap 'rm -f "$pending"' EXIT
docker compose exec -T www python3 /opt/sparrowfy/backup.py > "$pending"
python3 -m zipfile -t "$pending"
final="volumes/backups/$(date +%Y%m%dT%H%M%S)-${pending##*.backup-}.zip"
mv "$pending" "$final"
printf '%s\n' "$final"
