# Sparrow-Fy — entrega móvil 2026-11-06

Este documento prevalece sobre notas históricas de octubre. Contrato revisado contra el código desplegado; no equivale a una prueba de la app nativa.

## Dominio y archivos a integrar
- Sitio: https://sparrowfy.ddns.info/
- API JSON: https://sparrowfy.ddns.info/api/v1/index.php?r=...
- HLS inicial: https://sparrowfy.ddns.info/api/v1/stream.php?track_id=ID
- Hijos: /api/v1/segment.php?track_id=ID&file=RUTA_OPACA
- No usar /audio/ en URLs públicas. La ruta física /home/pi/docker/vaultwarden/www/audio y el montaje /var/www/html/audio no cambian.
- stream.php y segment.php comparten lib/mobile_hls.php. publicTrack en api/v1/index.php genera stream_url desde SCRIPT_NAME. Los cuatro archivos deben desplegarse juntos; no basta copiar los dos endpoints.

## Contrato HLS actualizado
1. Autenticar con auth/login y enviar Authorization: Bearer ACCESS_TOKEN en TODAS las peticiones: master, variantes, segmentos e inicialización. El servidor no inserta el token en URLs ni propaga automáticamente la cabecera: corresponde al cargador HLS de la app.
2. Resolver stream_url contra https://sparrowfy.ddns.info y seguir URLs devueltas, sin construir rutas físicas.
3. stream.php devuelve el master; segment.php sirve playlists hijas, TS, AAC, M4S y MP4. También reescribe atributos URI entre comillas, como EXT-X-MAP. Los hijos conservan track_id.
4. track_id es obligatorio también en segment.php. Descartar URLs antiguas sin ese parámetro y solicitar otro master. Cache-Control: private, no-store.
5. GET y HEAD: HEAD no cuenta bytes de audio ni devuelve cuerpo. Los binarios admiten un único Range de bytes (206); un rango inválido devuelve416. Manifiestos completos.
6. Cada archivo debe pertenecer al directorio HLS de la pista actual. Se rechazan rutas externas, otros esquemas, escapes fuera de la pista y extensiones ajenas a HLS.
7. 401: Bearer ausente, inválido, expirado, revocado o cuenta inactiva; reautenticar sin bucle infinito. 400: parámetros inválidos; 404: pista/archivo ausente o ajeno; 405: método no permitido; 416: rango inválido; 500: fallo interno/manifiesto inválido.
8. Bytes de GET registrados por el mismo spool HLS que la web: bytes entregados por PHP, sin sobrecarga TLS/IP; agregación diferida al panel. HEAD no incrementa audio. Esto no añade heartbeat Bearer de presencia ni endpoint nativo de gráficas.

## Responsabilidad del cliente
Auto/calidad, búfer, reconexión, cola, aleatorio/repetición, controles multimedia, accesibilidad y offline corresponden al reproductor nativo. HTTP/3 se negocia en el proxy; mantener fallback HTTP/2/TCP. El formato HLS no cambia.
La API conserva Bearer de30 días. No confundirlo con la cookie web Mantener sesión ni asumir revocación compartida de dispositivos. Usar auth/logout del token móvil.
me/preferences ofrece un perfil para configurar el reproductor; HLS no fuerza variantes ni normaliza volumen.

## Cambios web aún no expuestos como contrato Bearer
- WebCodecs (solo escritorio), importación/subida/reemplazo, álbum vacío, editor y subida de portada usan sesión web y CSRF. No llamar esos endpoints con Bearer ni inventar rutas v1.
- publicTrack aún no normaliza todos los metadatos, portada ni análisis de sonoridad. El volumen uniforme web no está aplicado al HLS: requiere contrato de datos y ganancia en el reproductor nativo. No anunciarlo como integrado.
- artwork_image.php requiere sesión web: una portada local subida no tiene aún endpoint Bearer de imagen. No depender de cookies web en la app. Las portadas externas públicas dependen del proveedor.
- loose_sort es un parámetro web, no implementado en los endpoints Bearer actuales. Ordenar los datos descargados o solicitar ampliación nativa. Las pistas reutilizadas conservan su track_id canónico; quitar una relación no equivale a borrar audio.

## Validación de integración requerida
Con token de prueba real: master, variante y dos segmentos; MIME, HEAD y Range; bytes en panel; revocar token y comprobar401 en la petición siguiente. Probar pérdida/regreso de señal en iPhone y Android físicos. Las pruebas del servidor no sustituyen estas pruebas nativas.

## Comprobaciones realizadas en esta entrega
PHP lint de los endpoints y helper; reescritura de playlist/EXT-X-MAP sin /audio/; aislamiento de directorios; Bearer válido en SQLite aislado; HTTP401 real sin Bearer en ambos endpoints; rangos cerrados, abiertos y sufijo; paquete documental disponible por HTTP200. No se ejecutó reproducción nativa completa ni se comprobó el contador con un token real de usuario. El YAML fue revisado como texto; no hay parser YAML instalado en el contenedor.
