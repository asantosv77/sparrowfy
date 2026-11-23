#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
url="${BASE_URL:-http://127.0.0.1:${WEB_PORT:-8081}}"
args=(--silent --show-error --max-time 8 -H "Host: ${SPARROWFY_HOST:-sparrowfy.ddns.info}")
for route in '/api/v1/index.php?r=health' '/api/v1/health'; do
    body=$(curl "${args[@]}" --fail "$url$route")
    python3 -c 'import json,sys; assert json.loads(sys.argv[1])["status"]=="ok"' "$body"
    printf '200 %s %s\n' "$route" "$body"
done
for route in '/index.php?view=favorites' '/player.php?album=smoke' '/stream.php?track_id=smoke'; do
    result=$(curl "${args[@]}" -o /dev/null -w '%{http_code} %{redirect_url}' "$url$route")
    case "$result" in
        30[12378]*login.php*|401*|403*) printf '%s %s\n' "$route" "$result" ;;
        *) echo "Respuesta inesperada: $route $result" >&2; exit 1 ;;
    esac
done
curl "${args[@]}" --fail -o /dev/null "$url/assets/player.js"
printf '200 /assets/player.js\n'
