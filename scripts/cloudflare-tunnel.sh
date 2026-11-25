#!/usr/bin/env bash
# Sparrow-Fy · Cloudflare Quick Tunnel (sin CGNAT ni cuenta de Cloudflare)
# Publica el servicio www detrás de una URL temporal https://*.trycloudflare.com.
set -euo pipefail
cd "$(dirname "$0")/.."

compose=(docker compose -f docker-compose.yml -f docker-compose.tunnel.yml)
case "$(uname -s)" in
  MINGW*|MSYS*|CYGWIN*) compose+=( -f docker-compose.windows.yml ) ;;
esac
if grep -qi microsoft /proc/version 2>/dev/null; then
  compose+=( -f docker-compose.windows.yml )
fi

cid() { "${compose[@]}" ps -q tunnel 2>/dev/null || true; }
url() {
  local id; id="$(cid)"
  [ -n "$id" ] || return 0
  docker logs "$id" 2>&1 | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -n 1 || true
}

case "${1:-start}" in
  start)
    echo "Levantando www y el túnel de Cloudflare..."
    "${compose[@]}" --profile tunel up -d tunnel
    echo "Esperando la URL pública (puede tardar hasta 2 minutos)..."
    for _ in $(seq 1 60); do
      u="$(url)"
      if [ -n "$u" ]; then
        echo
        echo "URL pública temporal: $u"
        echo "La URL cambia cada vez que se reinicia el túnel."
        exit 0
      fi
      sleep 2
    done
    echo "No apareció la URL. Revisa los logs:" >&2
    echo "  docker logs \$( ${compose[*]} ps -q tunnel )" >&2
    exit 1
    ;;
  url)
    u="$(url)"
    if [ -n "$u" ]; then echo "$u"; else echo "Túnel no activo o sin URL todavía." >&2; exit 1; fi
    ;;
  logs)
    id="$(cid)"; [ -n "$id" ] || { echo "Túnel no activo." >&2; exit 1; }
    docker logs -f "$id"
    ;;
  stop)
    echo "Deteniendo el túnel..."
    "${compose[@]}" --profile tunel stop tunnel
    ;;
  *)
    echo "Uso: $0 [start|url|logs|stop]" >&2
    exit 2
    ;;
esac
