# HLS Music Lab — API móvil v1 (iOS y Android)

> **Entrega vigente: 6 de noviembre de 2026.** Leer [dominio y HLS actualizados](MOBILE_API_UPDATE_2026-11-06.md). Base: `https://sparrowfy.ddns.info/api/v1/`. Reemplaza las instrucciones históricas de rutas HLS.

> Última entrega: la [guía móvil](MOBILE_DEVELOPER_HANDOFF.md#actualización-de-interfaz-y-reproducción--30102026) documenta paginación 25/50/100, colección virtual Favoritos en Inicio, reproducción continua/aleatoria, notificaciones flotantes y distribución compacta. Renombrar listas existe en web pero sigue pendiente en Bearer. Favoritos se pagina en el cliente; Recientes usa limit/offset. OpenAPI conserva únicamente rutas implementadas.

> Actualización de documentación: 30 de octubre de 2026. Leer primero [Guía de integración móvil](MOBILE_DEVELOPER_HANDOFF.md) para favoritos, recientes, listas propias, pistas sueltas y los dos modelos de compartir. El contrato Bearer disponible está en [OpenAPI](API_MOBILE_V1_OPENAPI.yaml). Los enlaces web con importación de copias aún no tienen endpoints Bearer.


API REST JSON sobre PHP 8.1+ y SQLite existente. La API no necesita que la app móvil use cookies web.

## Instalación
1. Haz una copia de seguridad de `/sparrowfy/database/library.sqlite`.
2. Copia `audio/api/v1/` a `/var/www/html/audio/api/v1/` y `audio/scripts/migrate_mobile_api.php` al contenedor.
3. Ejecuta **dentro del contenedor**, nunca con PHP del host:
   `docker exec www php /var/www/html/audio/scripts/migrate_mobile_api.php`
4. Ejecuta también `docker exec www php /var/www/html/audio/scripts/migrate_user_hls_preferences.php` para las preferencias HLS.
5. Comprueba `GET /api/v1/index.php?r=health`.

## Autenticación
`POST /api/v1/index.php?r=auth/login` con JSON `{"username":"...","password":"...","device_label":"iOS"}`. La respuesta contiene `access_token`; envíalo como `Authorization: Bearer <token>`. El token es persistente (no caduca por tiempo) y se almacena solo su SHA-256 en `sessions`; sigue vivo hasta `auth/logout`, revocación desde «Dispositivos y sesiones» o borrado de datos de la app. `POST ...?r=auth/logout` revoca el token. Guarda el token en Keychain (iOS) o Android Keystore/EncryptedSharedPreferences. Usa HTTPS.

## Endpoints principales
- `GET ?r=health` — salud (sin login).
- `GET ?r=tracks/{id}/cover` — carátula de una pista: portada local del álbum, carátula guardada en la pista o búsqueda externa (iTunes/Deezer/MusicBrainz) cacheada 24 h. Responde `cover_url` y `source` (`local`, `external` o `null`).
- `GET ?r=tracks/{id}/lyrics` — letra de la pista desde LRCLIB con fallback a lyrics.ovh, cacheada 7 días. Responde `synced` (LRC), `plain`, `source`, `title` y `artist`.
- `POST ?r=auth/login`, `GET ?r=auth/me`, `POST ?r=auth/logout`.
- `GET ?r=search&q=...&limit=30&offset=0` — **búsqueda combinada** de álbumes y melodías por título, nombre de pista, artista o nombre original. Requiere token Bearer. Responde con `query`, `albums[]`, `tracks[]`, `limit` y `offset`. Cada pista incluye `id`, `album_id`, `name`, `duration`, `position`, `renditions`, `stream_url` y `cover_url`, además de `album_title` y `album_artist`. Los álbumes incluyen `cover_url` y `track_count`.
- `GET ?r=library/albums&q=&limit=30&offset=0&order=recent` — explorar/buscar álbumes por título o artista. `order=recent` ordena por fecha de creación (excluye la colección reservada de pistas sueltas).
- `GET ?r=library/albums/{id}` — detalle y pistas.
- `GET ?r=library/tracks?q=&album_id=&limit=30&offset=0` — buscar pistas por nombre o nombre original; permite filtrar por álbum. Usar `album_id=fffffffffffffffe` para listar las pistas sin asignar a colección.
- `GET/POST ?r=me/favorites`; `DELETE ?r=me/favorites/{track_id}` — favoritos de pistas.
- `GET ?r=me/favorite-albums&limit=30&offset=0` — colecciones favoritas de la cuenta, devueltas como álbumes (con `cover_url`, `track_count` y `favorited_at`), no como pistas separadas.
- `POST ?r=me/favorite-albums` con `{"album_id":"..."}`; `DELETE ?r=me/favorite-albums/{album_id}` — añadir/quitar una colección completa de favoritos.
- `GET/POST ?r=me/recent` — historial y registrar reproducción.
- `GET ?r=charts&limit=10&offset=0` — ranking de la cuenta según `recent_tracks.play_count`; cada pista incluye `plays` y `last_played`.
- `GET/POST ?r=me/playlists`; `GET ?r=me/playlists/{id}`; `POST ?r=me/playlists/{id}/tracks`; `DELETE ?r=me/playlists/{id}/tracks/{track_id}`; `POST ?r=me/playlists/{id}/share` con `{"shared":true}`.
- `GET ?r=me/playlists/shared` — listas compartidas visibles.
- `POST ?r=me/suggestions` con `{"subject":"...","message":"..."}`.
- `GET /api/v1/stream.php?track_id={id}` — manifiesto HLS protegido por Bearer; los segmentos usan `segment.php`. En clientes nativos, enviar Authorization también para cada petición HLS.

## Compartir listas
La API permite compartir acceso a una lista en vivo con todos los usuarios autenticados (`{"shared":true}`) o con un usuario concreto (`{"username":"DESTINO","shared":true}`), mediante `POST me/playlists/{id}/share`. Solo el propietario la edita. Revocar usa `shared:false`. La web agrega otro mecanismo: un enlace aleatorio que permite al receptor autenticado importar una copia propia. Ese flujo todavía no tiene endpoints Bearer; véase la guía móvil para el acceso mediante navegador.

## Ejemplo curl
```sh
curl -s -X POST 'https://sparrowfy.ddns.info/api/v1/index.php?r=auth/login' \
  -H 'Content-Type: application/json' \
  -d '{"username":"USUARIO","password":"CONTRASEÑA","device_label":"Android"}'

curl -s 'https://sparrowfy.ddns.info/api/v1/index.php?r=library/albums&limit=20' \
  -H 'Authorization: Bearer TOKEN'
```

## Limitaciones de esta entrega
- La API aún no incorpora refresh-token separado ni límite de dispositivos. Los endpoints Bearer para generar/previsualizar/revocar enlaces aleatorios e importar copias están pendientes; no confundirlos con el endpoint existente de compartir acceso en vivo.
- El endpoint de stream HLS requiere que el cliente HTTP nativo propague el header Bearer a manifiestos y segmentos; en iOS AVPlayer no permite añadir headers a todas las peticiones de una playlist de forma sencilla. Para iOS se recomienda un `AVURLAsset` con `AVAssetResourceLoaderDelegate` o una estrategia de URL firmada de corta duración antes de producción.
- Los campos y tablas usan la base SQLite actual; verifica la migración en una copia de seguridad primero.



## Preferencias personales de reproducción HLS (implementadas)

Cada cuenta puede elegir su propio perfil HLS. La preferencia pertenece a la cuenta, no al dispositivo, por lo que debe sincronizarse entre la webapp, Android e iOS al iniciar sesión. No debe permitir al cliente enviar parámetros arbitrarios de `hls.js`; el cliente selecciona únicamente uno de los perfiles permitidos.

### Perfiles permitidos

| Valor API | Nombre visible | Comportamiento esperado | Compromiso |
|---|---|---|---|
| `continuity` | Asegurar transmisión continua | Más conservador al subir de nivel y prioriza disponer de búfer suficiente. | Puede tardar más en alcanzar la calidad máxima. |
| `balanced_mobile` | Equilibrado móvil | Balancea estabilidad, calidad y consumo de datos. Es el valor predeterminado recomendado. | No siempre elige el nivel más alto. |
| `audio_quality` | Priorizar calidad de audio | Más dispuesto a subir a niveles superiores cuando las descargas y el búfer indican que la conexión puede sostenerlos. | Consume más datos y puede aumentar el riesgo de pausas en redes inestables. |

Los perfiles no crean ni convierten rendiciones nuevas. El reproductor debe detectar los niveles realmente disponibles en cada `master.m3u8` y adaptarse a dos, tres o más niveles. Ningún perfil debe bloquear permanentemente el ABR ni forzar un bitrate que la conexión no pueda sostener. La selección manual de calidad, si existe, prevalece hasta que el usuario vuelva a seleccionar Automático.

### Endpoints de preferencias

> **Estado:** los endpoints de preferencias están incluidos en `api/v1/index.php`; la tabla se crea mediante `scripts/migrate_user_hls_preferences.php`. Ejecuta la migración antes de usar estos endpoints.

- `GET ?r=me/preferences` — devuelve las preferencias de la cuenta autenticada.
- `PATCH ?r=me/preferences/hls` — actualiza solo el perfil HLS de la cuenta autenticada. Body: `{"hls_profile":"balanced_mobile"}`.

Ejemplo de lectura:

```json
{
  "hls_profile": "balanced_mobile",
  "available_profiles": ["continuity", "balanced_mobile", "audio_quality"],
  "scope": "account"
}
```

Ejemplo de actualización:

```sh
curl -s -X PATCH 'https://sparrowfy.ddns.info/api/v1/index.php?r=me/preferences/hls' \
  -H 'Authorization: Bearer TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"hls_profile":"audio_quality"}'
```

Respuestas esperadas: `200` con la preferencia guardada; `400` si el perfil no pertenece a la lista permitida; `401` si falta el token o es inválido. La operación solo puede modificar la preferencia del usuario autenticado, nunca la de otra cuenta.

### Persistencia SQLite de preferencias

Añadir una tabla `user_preferences` mediante una migración idempotente, sin recrear ni modificar la identidad de los usuarios existentes:

```sql
CREATE TABLE IF NOT EXISTS user_preferences (
  user_id TEXT PRIMARY KEY,
  hls_profile TEXT NOT NULL DEFAULT 'balanced_mobile'
    CHECK (hls_profile IN ('continuity', 'balanced_mobile', 'audio_quality')),
  updated_at TEXT NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

La migración debe crear una fila predeterminada para las cuentas existentes con `INSERT OR IGNORE`. Al leer preferencias sin fila, el servidor debe responder `balanced_mobile` y crear o persistir el valor predeterminado de forma segura. Actualizar el perfil no debe cambiar permisos, rol, contraseña ni datos de sesión.

### Integración en web, Android e iOS

1. Tras autenticar, el cliente consulta `me/preferences` y aplica `hls_profile` al reproductor.
2. Al cambiar el perfil en Ajustes de reproducción, el cliente envía `PATCH me/preferences/hls` y actualiza la preferencia local solo después de confirmar éxito del servidor.
3. En un dispositivo nuevo se descarga la preferencia de la cuenta; no se debe confiar únicamente en almacenamiento local.
4. El perfil es una política ABR, no una calidad fija. La selección automática sigue utilizando los niveles disponibles y las mediciones actuales de red/búfer.
5. Para evitar carreras, el cliente no debe ejecutar a la vez el perfil automático y la lógica de calidad manual.
6. Si el endpoint no está disponible durante una actualización, usar temporalmente `balanced_mobile` y mostrar la preferencia guardada del servidor cuando se recupere la conexión.

La configuración concreta de `hls.js` debe definirse y validarse con pruebas de red para cada perfil; los nombres de perfil no deben interpretarse como garantía absoluta de reproducción sin cortes o de máxima fidelidad.

## Buscador de la webapp
La vista **Biblioteca musical** (`/index.php?view=library`) incluye un formulario de búsqueda que filtra álbumes y melodías por título, artista y nombre original. La búsqueda se procesa en PHP sobre el catálogo JSON de la webapp; al buscar, muestra álbumes coincidentes y pistas reproducibles. En móvil, el formulario se reorganiza verticalmente.

## Búsqueda desde Android e iOS
Ejemplo:
```sh
curl -sG 'https://sparrowfy.ddns.info/api/v1/index.php' \
  --data-urlencode 'r=search' \
  --data-urlencode 'q=mi universo' \
  --data-urlencode 'limit=20' \
  -H 'Authorization: Bearer TOKEN'
```
La búsqueda combinada requiere autenticación. Para paginar, incrementa `offset` usando el mismo `limit`. Las búsquedas de álbumes y pistas se devuelven por separado; el cliente puede presentar primero las canciones y después los álbumes o viceversa.

