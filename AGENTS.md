# AGENTS.md · Procedimiento de despliegue asistido / Assisted deployment runbook

> **Autoría / Authorship.** Este procedimiento fue redactado por **Big Pickle**, agente de **OpenCode**, a partir del inventario, las decisiones y las correcciones documentadas por el usuario en `SPARROWFY.md`, `README.md` y `CLOUDFLARE_TUNNEL.md`. También está disponible como `OPENCODE.md`, nombre del agente que lo escribió.
>
> This runbook was written by **Big Pickle**, an **OpenCode** agent, based on the inventory, decisions, and user corrections documented in `SPARROWFY.md`, `README.md`, and `CLOUDFLARE_TUNNEL.md`. It is also available as `OPENCODE.md`, named after the agent that wrote it.

Este archivo está escrito para que un agente de IA (OpenCode, Claude Code, Codex, Copilot, etc.) pueda desplegar **Sparrow-Fy** sin adivinar nada. Antes de ejecutar, lee también `README.md`, `SPARROWFY.md` y `CLOUDFLARE_TUNNEL.md`.

This file is meant for an AI agent (OpenCode, Claude Code, Codex, Copilot, etc.) to deploy **Sparrow-Fy** without guessing. Before running anything, also read `README.md`, `SPARROWFY.md`, and `CLOUDFLARE_TUNNEL.md`.

---

## Español

### 0. decide el entorno / Detect the environment

| Entorno | Archivos Compose obligatorios |
|---|---|
| Terminal Linux / macOS / WSL | `-f docker-compose.yml` |
| Docker Desktop en Windows (contenedores Linux) | `-f docker-compose.yml -f docker-compose.windows.yml` |

En Windows usa siempre los dos `-f`. En Linux **no** incluyas `docker-compose.windows.yml`. El archivo `docker-compose.tunnel.yml` es opcional y solo se usa para Cloudflare (paso 5).

Verifica prerequisites antes de seguir:

```bash
docker --version
docker compose version
```

Requiere Compose v2 y contenedores Linux. No uses `docker-compose` (guion bajo/heredado).

### 1. preparar configuración

Desde la raíz del proyecto:

```bash
cp .env.example .env
```

Revisa `.env` y ajusta como mínimo:

- `SPARROWFY_HOST`: el hostname que usará el proxy inverso (por defecto `sparrowfy.ddns.info`).
- `BIND_IP=127.0.0.1` y `WEB_PORT=8081`: escucha local. No los cambies si vas a usar el túnel de Cloudflare.
- `MEDIA_SOURCE`, `DATA_SOURCE`, `SESSION_SOURCE`, `BACKUP_SOURCE`: volúmenes con nombre; déjalos así salvo que necesites rutas propias.
- `SPARROWFY_CRON_ENABLED=0`: mantén desactivado el cron del contenedor en un despliegue paralelo.
- `GENIUS_ACCESS_TOKEN` y `MUSIXMATCH_API_KEY`: opcionales; déjalos vacíos si no los tienes.

**No** edites `app/config/schema.sql`, `app/src/auth.php`, `.env` de otro despliegue ni los backups.

### 2. construir y levantar

Linux / macOS / WSL:

```bash
docker compose -f docker-compose.yml build
docker compose -f docker-compose.yml up -d --wait --wait-timeout 120
```

Windows PowerShell:

```powershell
docker compose -f docker-compose.yml -f docker-compose.windows.yml build
docker compose -f docker-compose.yml -f docker-compose.windows.yml up -d --wait --wait-timeout 120
```

Si el servicio `www` ya estaba corriendo y quieres conservar datos antes de reconstruir, ejecuta primero `bash scripts/backup.sh` dentro del contenedor (en Windows, con los mismos `-f`).

### 3. verificar salud

El healthcheck usa `/usr/local/bin/sparrowfy-health`. Comprueba:

```bash
docker compose -f docker-compose.yml ps
```

`www` debe aparecer `(healthy)`. Luego la prueba de humo:

```bash
BASE_URL=http://127.0.0.1:8081 bash scripts/smoke-test.sh
```

En Windows, desde Git Bash con `BASE_URL` apuntando al puerto publicado:
```bash
BASE_URL="http://127.0.0.1:$(docker compose -f docker-compose.yml -f docker-compose.windows.yml port www 8080 | cut -d: -f2)" bash scripts/smoke-test.sh
```

La prueba espera `status=ok` en `/api/v1/index.php?r=health` y `/api/v1/health`, redirecciones a `login.php` en rutas privadas y `200` en `/assets/player.js`.

### 4. crear el primer administrador

```bash
docker compose -f docker-compose.yml exec www bash scripts/crear-admin.sh
```

Pide usuario y contraseña dos veces; la contraseña debe tener entre **14 y 72 bytes**. El script llama a `scripts/auth_setup.php`, que solo crea la cuenta si la base está vacía y respeta el administrador protegido.

> **Comprobado en Docker Desktop (25-Nov-2026):** con un volumen `app_data` nuevo, el alta creó el administrador y el login web funcionó. Si reutilizas un volumen existente, `auth_setup.php` responde `La cuenta principal ya está configurada. Se conservan sus credenciales.` y **no** crea otra cuenta. Comprueba primero con `docker compose -f docker-compose.yml exec www php scripts/auth_diagnose.php`; si ya hay un administrador activo, ve directo al reseteo de contraseña. Para una prueba aislada usa otro nombre de proyecto (`docker compose -p sf-prueba-20261125 -f docker-compose.yml ... up -d`) y así creas volúmenes nuevos sin tocar los existentes.

No interactivo:

```bash
docker compose -f docker-compose.yml exec \
  -e HLS_ADMIN_USER=admin -e HLS_ADMIN_PASSWORD='contraseña-larga-segura' \
  www php scripts/auth_setup.php
```

Abre `http://127.0.0.1:8081` y entra con esas credenciales.

Si olvidaste la contraseña, **no** vuelvas a ejecutar `auth_setup.php`. Usa el script de reseteo, que solo cambia la contraseña de un usuario existente:

```bash
docker compose -f docker-compose.yml exec \
  -e HLS_RESET_USER=admin -e HLS_RESET_PASSWORD='nueva-contraseña-larga' \
  www php scripts/auth_reset_password.php
```

O interactivo:

```bash
docker compose -f docker-compose.yml exec www bash scripts/crear-admin.sh --restablecer
```

Verificación: `docker compose -f docker-compose.yml exec www php scripts/auth_diagnose.php`. La contraseña nunca se imprime.

### 5. publicar en internet

El repo **no** incluye el proxy inverso. Tu esquema normal es `https://` en el puerto **443**, con letsencrypt usando el **80** para el challenge HTTP-01; eso se configura fuera de este compose (por ejemplo `nginx-proxy` + `acme-companion`) y requiere IP pública y port forwarding. No lo modifiques desde aquí.

Si hay **CGNAT** y no puedes abrir puertos, usa el túnel opcional (detalles en `CLOUDFLARE_TUNNEL.md`):

```bash
bash scripts/cloudflare-tunnel.sh start
```

En Windows:

```powershell
.\scripts\cloudflare-tunnel.ps1 -Action start
```

El script imprime una URL temporal `https://*.trycloudflare.com` que apunta al puerto **interno** `www:8080`. El `8080` no es el puerto del host; tus 443/80 quedan intactos. La URL cambia al reiniciar. Para detenerlo: `bash scripts/cloudflare-tunnel.sh stop`.

Para **descubrir la URL dinámica asignada** sin volver a levantar el túnel:

```bash
bash scripts/cloudflare-tunnel.sh url
```

En Windows:

```powershell
.\scripts\cloudflare-tunnel.ps1 -Action url
```

También se puede leer de los logs: `docker logs $(docker compose -f docker-compose.yml -f docker-compose.tunnel.yml -f docker-compose.windows.yml ps -q tunnel) 2>&1 | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -n 1`. En Linux omite el `-f docker-compose.windows.yml`.

### 6. comprobación final

- `docker compose ... ps` → `www` healthy.
- `GET /api/v1/index.php?r=health` → `{"status":"ok"}` (o equivalente).
- Login web con el admin creado.
- Si aplica, la URL pública redirige (`302`) a `login.php` y `/login.php` responde `200` con la página de acceso.

### Restricciones para el agente

- No instales APK ni pruebes en emuladores salvo petición explícita.
- No envíes archivos por WhatsApp.
- No sobrescribas `.env`, `docker-compose*.yml` propios, `SPARROWFY.md`, `INCIDENTE-CRON-UID-2026-11-24.md`, backups ni `propuestas/`.
- No reintroduzcas `sf_mobile_api_schema()` en `app/public/api/v1/index.php`: `deploy-sparrowfy` ya tiene las tablas en `app/config/schema.sql` y ese self-heal causaba `database is locked`.
- Usa siempre `php -l` sobre cualquier PHP modificado antes de darlo por bueno.

---

## English

### 0. Detect the environment

| Environment | Required Compose files |
|---|---|
| Linux / macOS / WSL terminal | `-f docker-compose.yml` |
| Docker Desktop on Windows (Linux containers) | `-f docker-compose.yml -f docker-compose.windows.yml` |

On Windows always pass both `-f` flags. On Linux **do not** include `docker-compose.windows.yml`. `docker-compose.tunnel.yml` is optional and only used for Cloudflare (step 5).

Check prerequisites first:

```bash
docker --version
docker compose version
```

Compose v2 and Linux containers are required. Do not use the legacy `docker-compose` command.

### 1. Prepare configuration

From the project root:

```bash
cp .env.example .env
```

Review `.env` and adjust at least:

- `SPARROWFY_HOST`: the hostname used by the reverse proxy (default `sparrowfy.ddns.info`).
- `BIND_IP=127.0.0.1` and `WEB_PORT=8081`: local listen. Leave them if you plan to use the Cloudflare tunnel.
- `MEDIA_SOURCE`, `DATA_SOURCE`, `SESSION_SOURCE`, `BACKUP_SOURCE`: named volumes; keep them unless you need custom paths.
- `SPARROWFY_CRON_ENABLED=0`: keep the container cron disabled in a parallel deployment.
- `GENIUS_ACCESS_TOKEN` and `MUSIXMATCH_API_KEY`: optional; leave empty if unavailable.

**Do not** edit `app/config/schema.sql`, `app/src/auth.php`, another deployment's `.env`, or the backups.

### 2. Build and start

Linux / macOS / WSL:

```bash
docker compose -f docker-compose.yml build
docker compose -f docker-compose.yml up -d --wait --wait-timeout 120
```

Windows PowerShell:

```powershell
docker compose -f docker-compose.yml -f docker-compose.windows.yml build
docker compose -f docker-compose.yml -f docker-compose.windows.yml up -d --wait --wait-timeout 120
```

If `www` was already running and you want to preserve data before rebuilding, run `bash scripts/backup.sh` inside the container first (with the same `-f` flags on Windows).

### 3. Verify health

The healthcheck runs `/usr/local/bin/sparrowfy-health`. Check:

```bash
docker compose -f docker-compose.yml ps
```

`www` must show `(healthy)`. Then run the smoke test:

```bash
BASE_URL=http://127.0.0.1:8081 bash scripts/smoke-test.sh
```

On Windows, from Git Bash with `BASE_URL` pointing at the published port:
```bash
BASE_URL="http://127.0.0.1:$(docker compose -f docker-compose.yml -f docker-compose.windows.yml port www 8080 | cut -d: -f2)" bash scripts/smoke-test.sh
```

The test expects `status=ok` on `/api/v1/index.php?r=health` and `/api/v1/health`, redirects to `login.php` on private routes, and `200` on `/assets/player.js`.

### 4. Create the first administrator

```bash
docker compose -f docker-compose.yml exec www bash scripts/crear-admin.sh
```

It asks for the username and password twice; the password must be **14 to 72 bytes**. The script calls `scripts/auth_setup.php`, which only creates the account when the database is empty and honors the protected admin.

> **Verified on Docker Desktop (25-Nov-2026):** with a fresh `app_data` volume, the setup created the admin and web login worked. If you reuse an existing volume, `auth_setup.php` prints `La cuenta principal ya está configurada. Se conservan sus credenciales.` and **does not** create another account. Check first with `docker compose -f docker-compose.yml exec www php scripts/auth_diagnose.php`; if an active admin already exists, go straight to the password reset. For an isolated test use a different project name (`docker compose -p sf-test-20261125 -f docker-compose.yml ... up -d`) so new volumes are created without touching existing ones.

Non-interactive:

```bash
docker compose -f docker-compose.yml exec \
  -e HLS_ADMIN_USER=admin -e HLS_ADMIN_PASSWORD='long-secure-password' \
  www php scripts/auth_setup.php
```

Open `http://127.0.0.1:8081` and sign in.

If you forgot the password, **do not** run `auth_setup.php` again. Use the reset script, which only changes the password of an existing user:

```bash
docker compose -f docker-compose.yml exec \
  -e HLS_RESET_USER=admin -e HLS_RESET_PASSWORD='new-long-password' \
  www php scripts/auth_reset_password.php
```

Or interactively:

```bash
docker compose -f docker-compose.yml exec www bash scripts/crear-admin.sh --restablecer
```

Verify with `docker compose -f docker-compose.yml exec www php scripts/auth_diagnose.php`. The password is never printed.

### 5. Publish to the internet

This repo **does not** include the reverse proxy. Your normal setup is `https://` on port **443**, with letsencrypt using port **80** for the HTTP-01 challenge; that is configured outside this compose (for example `nginx-proxy` + `acme-companion`) and needs a public IP and port forwarding. Do not change it from here.

If you are behind **CGNAT** and cannot forward ports, use the optional tunnel (see `CLOUDFLARE_TUNNEL.md`):

```bash
bash scripts/cloudflare-tunnel.sh start
```

On Windows:

```powershell
.\scripts\cloudflare-tunnel.ps1 -Action start
```

The script prints a temporary `https://*.trycloudflare.com` URL pointing at Docker's **internal** port `www:8080`. The `8080` is not the host port; your 443/80 stay untouched. The URL changes on restart. To stop it: `bash scripts/cloudflare-tunnel.sh stop`.

To **discover the assigned dynamic URL** again without restarting the tunnel:

```bash
bash scripts/cloudflare-tunnel.sh url
```

On Windows:

```powershell
.\scripts\cloudflare-tunnel.ps1 -Action url
```

You can also read it from the logs: `docker logs $(docker compose -f docker-compose.yml -f docker-compose.tunnel.yml -f docker-compose.windows.yml ps -q tunnel) 2>&1 | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -n 1`. On Linux omit the `-f docker-compose.windows.yml` flag.

### 6. Final checks

- `docker compose ... ps` → `www` healthy.
- `GET /api/v1/index.php?r=health` → `{"status":"ok"}` (or equivalent).
- Web login with the admin you created.
- If applicable, the public URL redirects (`302`) to `login.php` and `/login.php` returns `200` with the sign-in page.

### Agent constraints

- Do not install the APK or test in emulators unless explicitly asked.
- Do not send files via WhatsApp.
- Do not overwrite `.env`, project-specific `docker-compose*.yml`, `SPARROWFY.md`, `INCIDENTE-CRON-UID-2026-11-24.md`, backups, or `propuestas/`.
- Do not reintroduce `sf_mobile_api_schema()` into `app/public/api/v1/index.php`: `deploy-sparrowfy` already has the tables in `app/config/schema.sql`, and that self-heal caused `database is locked`.
- Run `php -l` on any modified PHP file before considering it done.
