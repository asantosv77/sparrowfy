# Sparrow-Fy: guía de integración móvil

## Actualización del 22 de noviembre de 2026

La web ofrece español de México e inglés de Estados Unidos. Guarda la elección en el navegador antes del login y permite cambiarla desde Mi cuenta. Android debe gestionar sus propios recursos de idioma. Los campos, claves de error y enumeraciones de la API conservan su contrato; la cookie de idioma de la web no es una preferencia Bearer. El instalador Windows se descarga en Mi PC como worker mediante sesión web con permisos de gestión. El APK utiliza la API móvil y no necesita instalar el worker ni manejar sus códigos de vinculación.

> **Entrega vigente: 6 de noviembre de 2026.** Leer [dominio y HLS actualizados](MOBILE_API_UPDATE_2026-11-06.md). Base: `https://sparrowfy.ddns.info/api/v1/`. Reemplaza las instrucciones históricas de rutas HLS.

Actualizado: 6 de noviembre de 2026. Contrato revisado contra `api/v1/index.php`, `lib/personal_library.php`, `api_personal.php` y el reproductor web. La actualización de noviembre corrige los endpoints HLS; ver la entrega vigente.

## Autenticación y transporte

Base desplegada: `https://sparrowfy.ddns.info/api/v1/index.php`.
Las operaciones se seleccionan con `r`, por ejemplo `?r=me/favorites`. Usar `Authorization: Bearer <access_token>` y, en POST/PATCH, `Content-Type: application/json`. El token se obtiene en `auth/login`; guardarlo en Keychain/Keystore. No incluir tokens en URLs, enlaces compartidos, registros ni mensajes de error.

La API móvil usa Bearer. La web usa una sesión PHP y CSRF: una sesión móvil no inicia sesión automáticamente en el navegador externo. `api_personal.php`, `api_library.php` y `api_convert.php` son rutas web; no son sustitutos de la API Bearer.

## Estado de las funciones

| Función | Web | API móvil disponible |
|---|---|---|
| Favoritos privados por cuenta | Estrella ☆/★ | GET/POST `me/favorites`, DELETE `me/favorites/{track_id}` |
| Reproducciones recientes | Registro cuando empieza a sonar | GET/POST `me/recent` |
| Crear y reproducir listas propias | Mis listas | GET/POST `me/playlists`, GET `me/playlists/{id}` |
| Añadir/quitar canciones de una lista | Añadir a lista / Quitar | POST `me/playlists/{id}/tracks`, DELETE `me/playlists/{id}/tracks/{track_id}` |
| Compartir acceso a la misma lista | Por nombre de usuario, con retirada de acceso | POST `me/playlists/{id}/share` |
| Descubrir listas compartidas | Compartidas conmigo | GET `me/playlists/shared` |
| Enlace aleatorio, vista previa e importación de una copia | Implementado en web | Sin endpoints Bearer; usar el flujo web |
| Subir pistas sueltas / moverlas a una colección | Implementado en web para gestores | Sin endpoints Bearer de subida/movimiento |

## Favoritos

GET `me/favorites` devuelve `{"items":[...]}`: pistas del usuario autenticado, más `album_title` y `favorited_at`. El servidor devuelve todos los favoritos e ignora limit/offset. Para ofrecer 25/50/100, paginar localmente la respuesta.

POST `me/favorites`, cuerpo `{"track_id":"ID_PISTA"}`, devuelve 201 y `{"success":true,"track_id":"ID_PISTA"}`. La inserción repetida no duplica el favorito. DELETE `me/favorites/ID_PISTA` devuelve 200 y `{"success":true}`; únicamente afecta al usuario autenticado.

La referencia web muestra la estrella en cada canción de la cola y en los listados; retiró la estrella duplicada de la barra superior. Mostrarla en cola y listados, con etiqueta accesible y estado seleccionado. Deshabilitar la acción mientras se guarda, confirmar el estado con el servidor y revertir cambios optimistas si falla. No enviar `user_id`: el servidor identifica al usuario por Bearer. Las estrellas web y móvil usan la misma tabla.

## Recientes

GET `me/recent&limit=30&offset=0` devuelve `items`, `limit` y `offset`, ordenados por `played_at` descendente. El límite permitido es 1–100. Cada usuario tiene una fila por pista; volver a reproducirla actualiza la fecha y aumenta un contador interno.

POST `me/recent`, cuerpo `{"track_id":"ID_PISTA"}`, devuelve `{"success":true}`. Enviar cuando el reproductor confirme que el audio empezó a sonar, una vez por carga/reproducción de pista. No registrar al abrir su ficha, precargar, intentar reproducir sin éxito, pausar/reanudar o recuperar un búfer. Volver a empezar intencionalmente una canción sí es otra reproducción.

Este POST no tiene clave de idempotencia: repetirlo incrementa el contador. Evitar reintentos automáticos ciegos después de un timeout y no enviar eventos desde dos componentes a la vez. El historial web está limitado visualmente a las últimas 100 pistas; la API móvil permite paginación. Las respuestas no garantizan devolver el contador interno.

## Listas propias y reproducción

POST `me/playlists` con `{"name":"Para el camino","description":"Mi selección"}` crea una lista privada y devuelve 201 con `id`, `name`, `is_shared:false`. Nombre: 1–120 caracteres.

GET `me/playlists` devuelve `{"items":[...]}` e incluye listas propias y otras accesibles. Separar visualmente Mis listas (`owner_id` igual al usuario de `auth/me`) de Compartidas. No asumir que todos los resultados son editables. La respuesta incluye `track_count`.

GET `me/playlists/ID_LISTA` devuelve `{"playlist":{...},"tracks":[...]}`. Las pistas están ordenadas por la posición dentro de esa lista; no asumir que comienza en 0 o en 1. Mantener el orden recibido.

POST `me/playlists/ID_LISTA/tracks`, `{"track_id":"ID_PISTA"}`, añade al final y devuelve 201 con `success:true`. No duplica la misma pista. DELETE `me/playlists/ID_LISTA/tracks/ID_PISTA` quita la asociación, no el archivo musical, y devuelve 200 con `success:true`. Solo el propietario puede modificarla. La API Bearer no ofrece renombrado, borrado de listas ni reordenación manual. La web sí permite renombrar listas propias; ver actualización al final.

Reproducir una lista consiste en una cola de `track_id` en el cliente. Para cada pista solicitar `/api/v1/stream.php?track_id=ID_PISTA` con Bearer. Los endpoints de favoritos, recientes y detalle de lista devuelven filas de catálogo y no garantizan `stream_url` ni `renditions`; no reutilizar ciegamente el modelo normalizado de `library/tracks`. Nunca reproducir `hls_path` como ruta pública ni fijar la ruta física en almacenamiento local. Propagar autenticación a manifiestos y segmentos HLS; revisar la limitación de AVPlayer descrita en README.

## Dos maneras distintas de compartir

### Web y API móvil: acceso a una lista en vivo

En la web, cada lista propia ofrece Compartir por usuario de Sparrow-Fy y muestra los destinatarios con un botón Retirar acceso. El receptor encuentra la lista en Compartidas conmigo y puede verla/reproducirla, pero no editarla ni volver a compartirla por usuario o enlace.

La API móvil usa POST `me/playlists/ID_LISTA/share`, siempre como propietario:

- `{"username":"usuario_destino","shared":true}` concede lectura a esa cuenta activa; false la revoca.
- `{"shared":true}` sin username marca la lista visible para todos los usuarios autenticados. No significa acceso anónimo por Internet. False retira esa visibilidad general.

La variante con username devuelve `success`, `shared_with` y `shared`. La variante sin username devuelve `success` e `is_shared`. GET `me/playlists/shared` devuelve listas ajenas accesibles, con `owner_username`. El receptor ve la misma lista: los cambios del propietario son visibles, pero no puede editarla. Compartir por usuario, publicar para usuarios autenticados y compartir por enlace son mecanismos independientes; revocar uno no revoca los otros.

### Web: enlace que permite obtener una copia

Formato desplegado:

```text
https://sparrowfy.ddns.info/index.php?view=playlists&share=TOKEN_OPACO
```

El token tiene 64 caracteres hexadecimales aleatorios. Solo su SHA-256 se guarda en `sf_playlist_links`. El enlace debe tratarse como una autorización de lectura e importación para quien lo posea y tenga una cuenta activa: no enviarlo a analítica ni registrar su query completa.

Flujo actual para móvil:

1. Abrir el enlace HTTPS en el navegador del sistema o en la web autenticada.
2. Si falta sesión web, iniciar sesión allí. No poner el token Bearer móvil en la URL.
3. Revisar nombre, propietario y pistas de la lista.
4. Pulsar Añadir a mis listas: el servidor crea una copia privada del receptor conservando el orden y las referencias de pista; no duplica el audio.
5. Al volver a la app, refrescar GET `me/playlists`. La copia ya pertenece al receptor y se puede editar desde los endpoints existentes.

Repetir la importación de la misma lista fuente devuelve la copia ya añadida. Es una instantánea: no sincroniza futuras modificaciones del original. Renovar el enlace invalida el anterior. Revocarlo impide nuevas vistas/importaciones por ese enlace; no elimina copias existentes.

**Pendiente para integración completamente nativa:** endpoints Bearer para crear/renovar/revocar enlace, previsualizar por token e importar. No existen todavía en `api/v1/index.php` y no se anuncian como operaciones disponibles en OpenAPI. La librería compartida de negocio es `lib/personal_library.php`; las operaciones web pasan por `api_personal.php`, con sesión PHP y CSRF. Tampoco se han configurado aquí Android App Links ni iOS Universal Links. Si la app intercepta esos enlaces, debe conservar el flujo web o esperar la extensión Bearer; no llamar rutas inventadas.

## Pistas sueltas y cambios de colección

La raíz de la biblioteca ya admite pistas sueltas. Por compatibilidad con el catálogo, se usa el contenedor interno reservado `fffffffffffffffe`, con `library_root:true` en los metadatos. Ocultarlo como ficha de álbum y mostrar sus canciones en «Pistas sin asignar a colección». Los endpoints de álbumes seleccionan columnas concretas y no garantizan exponer `library_root`, por lo que actualmente el ID reservado es la referencia interoperable.

Lectura móvil: GET `library/tracks&album_id=fffffffffffffffe`. Si no hay pistas, presentar estado vacío. La web permite subir varios archivos, seguir la cola y mover pistas a colecciones HLS mediante arrastre o selector. No se pueden mover a colecciones virtuales `online_links`. Estas operaciones de gestión aún no tienen contrato Bearer.

Mover una pista conserva su `track_id` y actualiza `album_id`, posición y ruta HLS en el catálogo. Los favoritos, recientes y listas continúan apuntando al mismo ID. Refrescar catálogo y solicitar el manifiesto por ID al reproducir; no identificar canciones por título, posición de álbum o ruta física. Una pista eliminada del catálogo puede desaparecer de una lista; gestionar cambios sin bloquear la reproducción de las restantes.

## Pantallas y sincronización recomendadas

- Favoritos: estado privado por usuario; consultar al iniciar sesión y al volver de la web.
- Recientes: consultar al entrar y después de una reproducción confirmada.
- Mis listas: separar propias de accesibles mediante `owner_id`; el rol admin no concede edición automática de listas ajenas en estas rutas.
- Detalle: cola ordenada, añadir/quitar pistas solo si es propietario.
- Compartidas: distinguir acceso en vivo de una copia importada por enlace.
- Cachés locales: separadas por ID de usuario; limpiar los datos de la cuenta al cerrar sesión. No mezclar listas/favoritos entre cuentas en el mismo dispositivo.

Para favoritos y listas se puede refrescar tras cada escritura. No hay feed push de cambios ni sincronización offline de conflictos en este contrato. Deshabilitar acciones mientras se guarda y mostrar errores sin perder la vista actual.

## Errores y validación de entrega

401: falta Bearer o es inválido/expirado. 403: se intentó modificar una lista accesible cuyo propietario es otro. 404: pista/lista inexistente, lista no accesible o usuario destino no activo, según la operación. 400: cuerpo o datos inválidos. Un fallo de transporte no equivale a una denegación de permisos. Leer siempre `error` y no asumir los mismos códigos de las rutas web.

Pruebas mínimas de la app:

1. A marca una estrella; B no la ve. Quitarla en la app se refleja en la web de A.
2. Una reproducción real registra un reciente; pausar/reanudar no envía otro evento. B no ve el historial de A.
3. A crea una lista, añade dos pistas y repite la primera: queda sin duplicados, en orden.
4. B no puede abrir una lista privada ni modificarla adivinando su ID.
5. Compartir por username permite lectura en vivo a B; revocar ese acceso lo retira si no existe otra vía de acceso.
6. El enlace web exige sesión, permite revisar y añadir una copia privada a B; volver a la app muestra esa copia.
7. Repetir la importación no duplica listas. Modificar la copia no cambia la fuente. Revocar el enlace impide nuevas importaciones, conservando copias existentes.
8. Mover una pista suelta a una colección conserva su estrella y presencia en listas; reproducirla por ID funciona con la nueva ruta.

Validación backend ya ejecutada: `scripts/test_personal_library.php` usa SQLite en memoria con dos cuentas ficticias para aislamiento, permisos, enlaces, copias e importación repetida. `scripts/test_library_move.php` cubre movimiento y recuperación ante fallo SQL. Estas pruebas no sustituyen las pruebas de integración de los clientes Android/iOS.

Archivos de referencia: `README_MOBILE_API_V1.md`, `API_MOBILE_V1_OPENAPI.yaml`, `PERSONAL_LIBRARY_NOTES.md` y `LIBRARY_ROOT_NOTES.md`.


## Actualización de interfaz y reproducción — 30/10/2026

### Paginación y búsquedas

| Vista | Web | Móvil con la API actual |
|---|---|---|
| Biblioteca / Canciones | 25, 50 o 100; 25 inicial | library/albums y library/tracks: limit=N y offset=(pagina-1)*N |
| Favoritos | 25, 50 o 100 | GET me/favorites completo; segmentar localmente |
| Recientes | 25, 50 o 100, dentro de las últimas 100 pistas | GET me/recent con limit/offset; la API no limita el historial total a 100 |
| Listas y sus pistas | Sin nuevo paginador | Listas y detalle devuelven colecciones completas |

`per_page` y `tracks_page` son parámetros web, no Bearer. La web pagina después de cargar metadatos. La API paginada no devuelve total ni cursor: una página corta indica final; una llena puede requerir otra consulta. Reiniciar offset al cambiar tamaño o búsqueda. «Reproducir favoritos» debe cargar toda la cola, no solo la página visible.

Se quitó el buscador inferior de Biblioteca porque duplicaba el superior. Inicio mantiene solo el superior. Canciones conserva su filtro propio. En móvil, `search` busca también por álbum/artista; `library/tracks&q=` filtra nombre de pista/nombre original. No son equivalentes: usar `search` para búsqueda global.

### Inicio y distribución

- Ficha privada «Mis favoritos» en Inicio, con Ver favoritos y Reproducir favoritos. Es una colección virtual de la cuenta: no crear álbumes ni duplicar pistas.
- Añadidos recientemente: colecciones y pistas sin asignar por fecha, máximo dos filas según ancho/zoom. Favoritos es una ficha independiente de ese límite.
- La API library/albums ordena por título: su primera página no representa últimos añadidos. Un feed reciente combinado nativo requeriría ampliar contrato o disponer del catálogo completo y fechas fiables; no se añadió endpoint.
- Biblioteca: colecciones primero; «Pistas sin asignar a colección» al final.
- Favoritos, Recientes y detalle de listas propias/compartidas: filas compactas, título/colección y controles alineados. Estrella al extremo derecho; adaptar a móvil sin ocultar acciones.
- Mis listas y Compartidas conmigo: una lista por fila; nombre con ajuste de línea, acciones de gestión solo para propietario. Ancho base web del nombre 210 px, máximo 240 px; no es un tamaño obligatorio en móvil.
- Canciones: reservar espacio a controles y ajustar títulos largos. Identificar pistas por track_id, no por número de fila.

### Renombrar listas: disponible solo en web

POST web `api_personal.php`: sesión PHP y CSRF, campos `action=rename_playlist`, `playlist_id`, `name`, `csrf_token`. Solo propietario; nombre recortado, no vacío, hasta 120 caracteres UTF-8, sin controles. Actualiza name/updated_at; conserva ID, pistas, enlaces y permisos. Devuelve playlist_id/name. Los receptores del acceso en vivo ven el cambio al refrescar; las copias importadas son independientes.

No existe PATCH/PUT ni ruta rename equivalente en `api/v1/index.php`. No inventar un endpoint ni borrar/recrear la lista para cambiarle el nombre. Pendiente: operación Bearer de propietario con el mismo comportamiento. Mientras tanto, ofrecer flujo web o indicar que esa edición no está disponible en nativo.

### Reproductor: continua / aleatoria

Estado local del cliente; sin endpoint y sin modificar el orden guardado de la lista. La web inicia en continua en cada instancia y no persiste el modo entre aperturas.

- Continua: avanza por orden y al terminar la última pista vuelve a la primera.
- Aleatoria: flechas cruzadas SVG; icono atenuado apagado, resaltado encendido, estado accesible seleccionado (aria-pressed en web).
- Mezcla de pistas restantes sin repetir durante el ciclo normal. Al agotarlo, nueva mezcla excluyendo la pista actual para evitar repetición inmediata. Una cola de una pista vuelve a esa pista.
- Anterior en aleatorio usa historial (hasta 200 entradas en web); sin historial, retrocede por posición. Cambiar modo limpia mezcla/historial sin interrumpir audio actual.
- Mismo avance para final de audio, Siguiente y controles multimedia del sistema. La selección manual continúa disponible. Aleatorio opera sobre la cola cargada, no sobre todo el catálogo.
- Avisos: «Reproducción aleatoria» / «Reproducción continua».

Barra superior: selector de lista y Añadir a lista a la izquierda; controles de ventana a la derecha. Portada/título debajo. Estrella en cada fila de la cola; pulsarla no selecciona otra canción ni inicia reproducción.

Notificaciones flotantes: unos 2,6 segundos visibles y fadeout de 0,3 segundos, sin texto fijo debajo ni cambios de altura. Respetar reducción de movimiento y anunciar el resultado para lectores de pantalla. La estrella confirma el estado guardado; si falla la petición, mostrar error y no marcar un éxito falso.

### Comprobaciones adicionales para Android/iOS

1. Páginas 25/50/100, vacío, última página y cambio de tamaño; favoritos paginados localmente.
2. Reproducir todos los favoritos desde Inicio independientemente de la página visible; estado vacío apropiado.
3. Alternar modos sin reiniciar audio; comprobar vuelta al inicio, ciclo sin repetición, Anterior y cola de una pista.
4. Marcar favorito desde la cola conserva reproducción y sincroniza estrellas; aviso desaparece sin desplazar contenido.
5. Listas ajenas de solo lectura; renombrado nativo pendiente hasta contar con ruta Bearer.
6. Anchos pequeños, títulos largos, texto ampliado y accesibilidad de acciones/avisos.

Se verificaron sintaxis PHP/JS y lógica de paginación, aleatorio/continua y renombrado con rechazo de usuario ajeno y nombre vacío. No se ejecutaron pruebas nativas Android/iOS en esta entrega.
