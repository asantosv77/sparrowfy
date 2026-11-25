# Publicar Sparrow-Fy con Cloudflare Tunnel / Publishing Sparrow-Fy with Cloudflare Tunnel

[Español](#español) · [English](#english)

## Español

Sparrow-Fy se publica normalmente en `https://` mediante un proxy inverso en el puerto **443**, con **letsencrypt** usando el puerto **80** para el desafío HTTP-01. Ese es el esquema recomendado cuando tienes IP pública.

Si tu proveedor aplica **CGNAT** no tienes IP pública y no puedes abrir puertos en el router: el 443/80 del host no sirve desde internet. Un *tunnel* de Cloudflare es una **alternativa opcional** para ese caso. Invierte la conexión: el contenedor `cloudflared` sale hacia Cloudflare y te devuelve una URL `https://*.trycloudflare.com` que apunta al **puerto interno** `www:8080` de Docker. No necesitas cuenta, dominio ni abrir puertos, y **no modifica** tu configuración actual de 443/80 ni el challenge de letsencrypt.

> El `8080` de `http://www:8080` es el puerto **dentro de la red de Docker**, no el puerto publicado en el host. Tu 443 y el 80 de ACME siguen como están. El ejemplo de `drawio` usa otros puertos solo como referencia; son relativos a ese proyecto.

> **Importante.** El túnel rápido es **temporal**: la URL cambia cada vez que se reinicia el contenedor y se pierde si el equipo se apaga. Para un enlace permanente necesitas un *named tunnel* con cuenta de Cloudflare (al final de esta guía).

### Requisitos

- Docker Compose con contenedores Linux ya funcionando (`docker compose up -d --build --wait`).
- El archivo `docker-compose.tunnel.yml` incluido en este repositorio.

### Uso rápido

En Linux, macOS o WSL:

```bash
bash scripts/cloudflare-tunnel.sh start
```

En Windows PowerShell:

```powershell
.\scripts\cloudflare-tunnel.ps1 -Action start
```

El script levanta el perfil `tunel`, espera hasta 2 minutos a que `cloudflared` registre su URL y la imprime:

```
URL pública temporal: https://abc123-def456.trycloudflare.com
```

Abre esa URL en cualquier navegador o configúrala como *Server URL* en la app Android. El tráfico va por HTTPS, así que no hace falta convertir `http://` en `https://` a mano.

Otros comandos:

```bash
bash scripts/cloudflare-tunnel.sh url     # solo la URL actual
bash scripts/cloudflare-tunnel.sh logs    # logs en directo
bash scripts/cloudflare-tunnel.sh stop    # detener el túnel
```

### Cómo funciona (equivalente manual)

El contenedor del ejemplo `drawio` publica `drawio-app:8080` con `cloudflared tunnel --url`. Aquí ocurre lo mismo con `www:8080`:

```yaml
  tunnel:
    image: cloudflare/cloudflared@sha256:e613aadbc2d9511785d2e67a86b0c8ac18dd63a3f9af0d06faf04cbfdcb35f2d
    command: ["tunnel", "--no-autoupdate", "--url", "http://www:8080"]
    profiles: ["tunel"]
    restart: "no"
    read_only: true
    cap_drop: [ALL]
    security_opt: ["no-new-privileges:true"]
    depends_on:
      www:
        condition: service_healthy
```

La URL se lee de los logs. En Linux/macOS:

```bash
docker logs sparrowfy-deploy-tunnel-1 2>&1 \
  | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -n 1
```

En Windows PowerShell:

```powershell
docker logs sparrowfy-deploy-tunnel-1 2>&1 |
  Select-String -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -AllMatches |
  ForEach-Object { $_.Matches } |
  Select-Object -Last 1 -ExpandProperty Value
```

El nombre del contenedor puede variar si cambiaste `COMPOSE_PROJECT_NAME`. Obtén el real con:

```bash
docker compose -f docker-compose.yml -f docker-compose.tunnel.yml --profile tunel ps -q tunnel
```

En Docker Desktop para Windows añade `-f docker-compose.windows.yml` a ese comando.

### Seguridad

- El túnel publica **toda** la web, incluido el login. Usa contraseñas largas y no compartas la URL.
- La URL temporal no es un secreto: cualquiera que la conozca puede intentar entrar. La protección es el login de Sparrow-Fy, no la oscuridad del enlace.
- No expongas `mornye-api`, `spotify-charts` ni sus puertos VNC por este túnel: el comando apunta solo a `www:8080`.
- Si vas a dejar el túnel activo permanentemente, usa un *named tunnel* y Cloudflare Access.

### Túnel permanente (opcional)

1. Crea una cuenta en Cloudflare y, en **Zero Trust → Networks → Tunnels**, crea un túnel.
2. Copia el token que muestra Cloudflare y guárdalo fuera del repositorio, por ejemplo en `.env` como `CLOUDFLARE_TUNNEL_TOKEN=...`.
3. Sustituye el `command` del servicio `tunnel` en `docker-compose.tunnel.yml` por:

   ```yaml
   command: ["tunnel", "--no-autoupdate", "run", "--token", "${CLOUDFLARE_TUNNEL_TOKEN}"]
   ```

4. En **Zero Trust → Networks → Tunnels → Public Hostname**, añade un hostname que apunte a `HTTP://www:8080`.
5. Levanta el túnel con `docker compose ... --profile tunel up -d tunnel`.

Con un named tunnel la URL es fija y puedes añadir reglas de Cloudflare Access si no quieres depender solo del login de la app.

### Primer administrador y reseteo de contraseña

El primer usuario administrador se crea **dentro del contenedor**, con el asistente interactivo:

```bash
docker compose exec www bash scripts/crear-admin.sh
```

Pide el usuario y la contraseña dos veces. La contraseña debe tener entre 14 y 72 bytes. El script llama a `scripts/auth_setup.php`, que solo crea la cuenta si la base está vacía; si ya existen usuarios, conserva el administrador protegido y no sobrescribe nada. Esto se comprobó en Docker Desktop el 25-Nov-2026: con volúmenes nuevos creó el admin y el login funcionó; con un volumen `app_data` reutilizado el script solo conserva las credenciales existentes. Comprueba primero con `docker compose exec www php scripts/auth_diagnose.php`; si ya hay un admin activo, usa directamente el reseteo de contraseña.

También puedes hacerlo sin interfaz, con variables de entorno:

```bash
docker compose exec -e HLS_ADMIN_USER=admin -e HLS_ADMIN_PASSWORD='contraseña-larga' \
  www php scripts/auth_setup.php
```

Si olvidaste la contraseña del administrador, usa el script **de reseteo** (`scripts/auth_reset_password.php`). Solo cambia la contraseña de un usuario **existente**; no crea cuentas ni activa usuarios inactivos:

```bash
docker compose exec -e HLS_RESET_USER=admin -e HLS_RESET_PASSWORD='nueva-contraseña-larga' \
  www php scripts/auth_reset_password.php
```

O de forma interactiva:

```bash
docker compose exec www bash scripts/crear-admin.sh --restablecer
```

Comprueba el resultado con:

```bash
docker compose exec www php scripts/auth_diagnose.php
```

La contraseña nunca se imprime en pantalla ni se guarda en los logs.

## English

Sparrow-Fy is normally published over `https://` through a reverse proxy on port **443**, with **letsencrypt** using port **80** for the HTTP-01 challenge. That is the recommended setup when you have a public IP.

If your ISP uses **CGNAT** you have no public IP and cannot forward ports on the router, so the host's 443/80 are unreachable from the internet. A Cloudflare Tunnel is an **optional alternative** for that case. It reverses the connection: the `cloudflared` container reaches out to Cloudflare and gives you an `https://*.trycloudflare.com` URL pointing at Docker's **internal** port `www:8080`. No account, domain, or port forwarding required, and it **does not change** your existing 443/80 setup or the letsencrypt challenge.

> The `8080` in `http://www:8080` is the port **inside the Docker network**, not the host-published port. Your 443 and the ACME port 80 stay as they are. The `drawio` example uses different ports only as a reference; they are relative to that project.

> **Important.** A Quick Tunnel is **temporary**: the URL changes every time the container restarts and disappears when the machine shuts down. For a permanent link, use a named tunnel with a Cloudflare account (see the end of this guide).

### Requirements

- Docker Compose with Linux containers already working (`docker compose up -d --build --wait`).
- The `docker-compose.tunnel.yml` file included in this repository.

### Quick start

On Linux, macOS, or WSL:

```bash
bash scripts/cloudflare-tunnel.sh start
```

On Windows PowerShell:

```powershell
.\scripts\cloudflare-tunnel.ps1 -Action start
```

The script starts the `tunel` profile, waits up to two minutes for `cloudflared` to register its URL, and prints it:

```
URL pública temporal: https://abc123-def456.trycloudflare.com
```

Open that URL in any browser, or set it as the *Server URL* in the Android app. Traffic is HTTPS, so you do not need to rewrite `http://` to `https://` manually.

Other commands:

```bash
bash scripts/cloudflare-tunnel.sh url     # print only the current URL
bash scripts/cloudflare-tunnel.sh logs    # follow logs
bash scripts/cloudflare-tunnel.sh stop    # stop the tunnel
```

### How it works (manual equivalent)

The sample `drawio` container publishes `drawio-app:8080` with `cloudflared tunnel --url`. The same happens here with `www:8080`:

```yaml
  tunnel:
    image: cloudflare/cloudflared@sha256:e613aadbc2d9511785d2e67a86b0c8ac18dd63a3f9af0d06faf04cbfdcb35f2d
    command: ["tunnel", "--no-autoupdate", "--url", "http://www:8080"]
    profiles: ["tunel"]
    restart: "no"
    read_only: true
    cap_drop: [ALL]
    security_opt: ["no-new-privileges:true"]
    depends_on:
      www:
        condition: service_healthy
```

The URL is read from the logs. On Linux/macOS:

```bash
docker logs sparrowfy-deploy-tunnel-1 2>&1 \
  | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -n 1
```

On Windows PowerShell:

```powershell
docker logs sparrowfy-deploy-tunnel-1 2>&1 |
  Select-String -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -AllMatches |
  ForEach-Object { $_.Matches } |
  Select-Object -Last 1 -ExpandProperty Value
```

The container name may differ if you changed `COMPOSE_PROJECT_NAME`. Get the real one with:

```bash
docker compose -f docker-compose.yml -f docker-compose.tunnel.yml --profile tunel ps -q tunnel
```

On Docker Desktop for Windows add `-f docker-compose.windows.yml` to that command.

### Security

- The tunnel publishes the **whole** web app, including the login page. Use long passwords and do not share the URL.
- A temporary URL is not a secret: anyone who knows it can try to sign in. Protection comes from Sparrow-Fy's login, not from an obscure link.
- Do not expose `mornye-api`, `spotify-charts`, or their VNC ports through this tunnel: the command points only to `www:8080`.
- If you leave the tunnel running permanently, use a named tunnel plus Cloudflare Access.

### Permanent tunnel (optional)

1. Create a Cloudflare account and, under **Zero Trust → Networks → Tunnels**, create a tunnel.
2. Copy the token Cloudflare shows and keep it outside the repository, for example in `.env` as `CLOUDFLARE_TUNNEL_TOKEN=...`.
3. Replace the `command` of the `tunnel` service in `docker-compose.tunnel.yml` with:

   ```yaml
   command: ["tunnel", "--no-autoupdate", "run", "--token", "${CLOUDFLARE_TUNNEL_TOKEN}"]
   ```

4. Under **Zero Trust → Networks → Tunnels → Public Hostname**, add a hostname pointing to `HTTP://www:8080`.
5. Start it with `docker compose ... --profile tunel up -d tunnel`.

A named tunnel gives you a fixed URL and lets you add Cloudflare Access rules if you do not want to rely only on the app's login.

### First admin and password reset

The first administrator is created **inside the container** with the interactive assistant:

```bash
docker compose exec www bash scripts/crear-admin.sh
```

It asks for the username and the password twice. The password must be 14 to 72 bytes long. The script calls `scripts/auth_setup.php`, which only creates the account when the database is empty; if users already exist, it keeps the protected admin and overwrites nothing. This was verified on Docker Desktop on 25-Nov-2026: with fresh volumes it created the admin and login worked; with a reused `app_data` volume the script only keeps the existing credentials. Check first with `docker compose exec www php scripts/auth_diagnose.php`; if an active admin already exists, go straight to the password reset.

You can also create it non-interactively with environment variables:

```bash
docker compose exec -e HLS_ADMIN_USER=admin -e HLS_ADMIN_PASSWORD='long-password' \
  www php scripts/auth_setup.php
```

If you forgot the admin password, use the **reset** script (`scripts/auth_reset_password.php`). It only changes the password of an **existing** user; it does not create accounts or reactivate inactive users:

```bash
docker compose exec -e HLS_RESET_USER=admin -e HLS_RESET_PASSWORD='new-long-password' \
  www php scripts/auth_reset_password.php
```

Or interactively:

```bash
docker compose exec www bash scripts/crear-admin.sh --restablecer
```

Verify with:

```bash
docker compose exec www php scripts/auth_diagnose.php
```

The password is never printed on screen or stored in logs.
