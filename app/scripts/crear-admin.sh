#!/usr/bin/env bash
set -euo pipefail
case "${1:-}" in
    '') mode=crear ;;
    --restablecer) mode=restablecer ;;
    *) echo 'Uso: crear-admin.sh [--restablecer]' >&2; exit 2 ;;
esac
IFS= read -r -p 'Usuario del administrador: ' admin_name
IFS= read -r -s -p 'Contraseña (14 a 72 bytes): ' admin_password
printf '\n'
IFS= read -r -s -p 'Repite la contraseña: ' confirmation
printf '\n'
[[ "$admin_password" == "$confirmation" ]] || { echo 'Las contraseñas deben coincidir.' >&2; exit 2; }
if [[ "$mode" == restablecer ]]; then
    HLS_RESET_USER="$admin_name" HLS_RESET_PASSWORD="$admin_password" php "$(dirname "$0")/auth_reset_password.php"
else
    HLS_ADMIN_USER="$admin_name" HLS_ADMIN_PASSWORD="$admin_password" php "$(dirname "$0")/auth_setup.php"
fi

