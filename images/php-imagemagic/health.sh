#!/bin/sh
set -eu
curl -fsS --max-time 5 -H "Host: ${SPARROWFY_HOST:-localhost}" 'http://127.0.0.1:8080/api/v1/index.php?r=health' |
    php -r '$r=json_decode(stream_get_contents(STDIN),true); exit(($r["status"]??"")==="ok"?0:1);'
