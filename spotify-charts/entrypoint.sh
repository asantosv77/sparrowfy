#!/bin/sh
set -eu

display_file=$(mktemp)
Xvfb -displayfd 3 -screen 0 1280x900x24 -nolisten tcp 3>"$display_file" >/tmp/xvfb.log 2>&1 &
xvfb_pid=$!
for attempt in 1 2 3 4 5 6 7 8 9 10; do
  if [ -s "$display_file" ]; then break; fi
  if ! kill -0 "$xvfb_pid" 2>/dev/null; then break; fi
  sleep 1
done
if ! IFS= read -r display_number < "$display_file"; then
  rm -f -- "$display_file"
  cat /tmp/xvfb.log >&2
  exit 1
fi
rm -f -- "$display_file"
export DISPLAY=":$display_number"

x11vnc -display "$DISPLAY" -localhost -forever -shared -nopw -rfbport 5900 -noxrecord -noxfixes >/tmp/x11vnc.log 2>&1 &
vnc_pid=$!
for attempt in 1 2 3 4 5; do
  if node /app/healthcheck.js --vnc-only; then break; fi
  if ! kill -0 "$vnc_pid" 2>/dev/null; then break; fi
  sleep 1
done
if ! node /app/healthcheck.js --vnc-only; then
  cat /tmp/x11vnc.log >&2
  exit 1
fi

websockify --web=/usr/share/novnc 0.0.0.0:6080 127.0.0.1:5900 >/tmp/websockify.log 2>&1 &
exec node /app/server.js
