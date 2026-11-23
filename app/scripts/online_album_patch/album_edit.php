<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
lab_auth_require_album_manager();
$cfg = lab_load_config();
$id = isset($_GET['id']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['id']) : '';
$album = $id ? lab_load_album($id) : null;
$err = '';
$ok = '';
$accept = implode(',', array_map(static fn ($e) => '.' . $e, $cfg['allowed_extensions']));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    lab_require_same_origin_post();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_meta' && $album) {
            $album['title'] = trim((string) ($_POST['title'] ?? $album['title'])) ?: $album['title'];
            $album['artist'] = trim((string) ($_POST['artist'] ?? ''));
            lab_save_album($album);
            // Los álbumes virtuales no deben crear carpetas HLS locales.
            if (($album['album_type'] ?? '') !== 'online_links') {
                lab_album_hls_dir($album['id'], $album['title']);
            }
            $ok = 'Datos del álbum guardados.';
        } elseif ($action === 'cover' && $album) {
            if (empty($_FILES['cover']) || ($_FILES['cover']['error'] ?? 0) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Selecciona una imagen de portada.');
            }
            $c = $_FILES['cover'];
            if (($c['size'] ?? 0) > 8 * 1024 * 1024 || !is_uploaded_file((string)($c['tmp_name'] ?? ''))) throw new RuntimeException('La portada supera 8 MiB o la subida no es válida.');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($c['tmp_name']) ?: '';
            if (!preg_match('#^image/(jpeg|png|webp|gif)$#', $mime) || @getimagesize($c['tmp_name']) === false) {
                throw new RuntimeException('Solo JPG, PNG, WebP o GIF.');
            }
            $ext = str_contains($mime, 'png') ? 'png' : (str_contains($mime, 'webp') ? 'webp' : (str_contains($mime, 'gif') ? 'gif' : 'jpg'));
            $fname = 'cover.' . $ext;
            $dest = LAB_ALBUMS . '/' . $album['id'] . '/' . $fname;
            if (!move_uploaded_file($c['tmp_name'], $dest)) throw new RuntimeException('No se pudo guardar la portada.');
            $album['cover'] = $fname;
            $album['cover_layout'] = [
                'x' => max(0, min(100, (float) ($_POST['cover_x'] ?? 50))),
                'y' => max(0, min(100, (float) ($_POST['cover_y'] ?? 50))),
                'zoom' => max(50, min(200, (float) ($_POST['cover_zoom'] ?? 100))),
            ];
            lab_save_album($album);
            $ok = 'Portada y encuadre guardados.';
        } elseif ($action === 'reconstruct' && $album) {
            $job = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) ($_POST['job_id'] ?? '')) ?? '';
            lab_reconstruct_source($job);
            $ok = 'Source reconstruido (reconstructed.m4a) en el job ' . $job;
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
        if ($id) $album = lab_load_album($id);
    }
    if ($id) $album = lab_load_album($id);
}

$layout = $album['cover_layout'] ?? ['x' => 50, 'y' => 50, 'zoom' => 100];
$coverUrl = ($album && !empty($album['cover']))
    ? 'data/albums/' . rawurlencode($album['id']) . '/' . rawurlencode($album['cover'])
    : '';
$trackCount = $album ? count($album['tracks'] ?? []) : 0;
$isOnlineAlbum = $album && (($album['album_type'] ?? '') === 'online_links');
?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <meta name="application-name" content="HLS Music Lab">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $album ? lab_h($album['title']) : 'Nuevo álbum' ?> · Lab</title>
  <link rel="stylesheet" href="assets/lab.css">

  <style>
    /* Alcance exclusivo: editor de álbum */
    body:has(.lab-tracks-card) .lab-main { max-width: 1600px; width: calc(100% - 32px); margin-inline: auto; box-sizing: border-box; }
    .lab-tracks-card { width: 100%; min-width: 0; box-sizing: border-box; }
    .lab-tracks-scroll { width: 100%; overflow-x: auto; }
    .lab-tracks-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: .9rem; }
    .lab-tracks-table th { text-align: left; color: #a8b9d2; padding: 12px 10px; border-bottom: 1px solid #354258; }
    .lab-tracks-table td { padding: 10px; border-bottom: 1px solid #283448; vertical-align: middle; min-width: 0; }
    .lab-tracks-table th:nth-child(1) { width: 4%; }
    .lab-tracks-table th:nth-child(2) { width: 26%; }
    .lab-tracks-table th:nth-child(3) { width: 13%; }
    .lab-tracks-table th:nth-child(4) { width: 34%; }
    .lab-tracks-table th:nth-child(5) { width: 23%; }
    .lab-track-name strong { overflow-wrap: anywhere; }
    .lab-track-quality small { display: block; color: #a8b9d2; margin-top: 3px; }
    .lab-track-path code { display: block; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; max-width: 100%; font-size: .76rem; }
    .lab-track-buttons { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
    .lab-track-buttons form { display: contents; }
    .lab-icon-btn { display: inline-flex; flex: 1 1 0; min-width: 0; min-height: 44px; padding: 5px 4px; flex-direction: column; align-items: center; justify-content: center; gap: 2px; border: 0; border-radius: 6px; color: white; text-decoration: none; cursor: pointer; font: inherit; font-size: .64rem; font-weight: 700; line-height: 1.1; }
    .lab-icon-btn svg { width: 19px; height: 19px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
    .lab-icon-btn span { overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
    .lab-icon-btn:hover { filter: brightness(1.12); }
    .lab-icon-btn:focus-visible { outline: 3px solid #fff; outline-offset: 2px; }
    .lab-icon-btn:disabled { opacity: .55; cursor: wait; }
    .lab-btn-play { background: #08a980; flex: 0 0 36px; }
    .lab-btn-rebuild { background: #3b82f6; }
    .lab-btn-edit { background: #22a66a; }
    .lab-btn-delete { background: #d93e49; }
    /* Paneles de edición: dos columnas en escritorio y una en pantallas estrechas. */
    .lab-album-layout { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; align-items: stretch; margin-bottom: 12px; }
    .lab-album-layout > .card { margin: 0; min-width: 0; box-sizing: border-box; }
    .lab-album-layout #albumMetaCard { grid-column: 1; grid-row: 1; border: 1px solid #08a980; }
    .lab-album-layout #onlineAlbumCard { grid-column: 2; grid-row: 1; }
    .lab-album-layout #youtubeImportCard { grid-column: 1; grid-row: 2; min-width: 0; min-height: 500px; }
    .lab-album-layout #addTracksForm { grid-column: 2; grid-row: 2; min-height: 500px; }
    .lab-album-layout .album-cover-card { grid-column: 1 / -1; }
    /* Portada compacta a la izquierda y encuadre alineado a la derecha. */
    .album-cover-card .cover-editor-layout { display: grid; grid-template-columns: 220px minmax(220px, 1fr); gap: 24px; align-items: center; }
    .album-cover-card .cover-image-column { min-width: 0; }
    .album-cover-card .cover-preview { width: 100%; max-width: 180px; aspect-ratio: 1 / 1; height: auto; min-height: 0; box-sizing: border-box; margin: 0 0 12px; border-radius: 10px; background-position: center; background-size: cover; overflow: hidden; }
    .album-cover-card .cover-file-label { display: block; margin: 0 0 5px; }
    .album-cover-card .cover-file-input { display: block; width: 100%; max-width: 220px; font-size: .8rem; }
    .album-cover-card .cover-controls { width: 100%; max-width: 360px; min-width: 0; }
    .album-cover-card .cover-control { margin-bottom: 10px; }
    .album-cover-card .cover-control label { display: block; margin-bottom: 3px; font-weight: 600; }
    .album-cover-card .cover-control input[type=range] { display: block; width: 100%; margin: 0; }
    .album-cover-card .cover-save { margin-top: 10px; }
    .lab-online-idea { border: 1px dashed #08a980; background: linear-gradient(145deg, rgba(8,169,128,.08), transparent 60%); }
    /* Textareas ocupan todo el ancho útil, con padding moderado y scroll si crece el contenido. */
    .lab-album-layout textarea { display: block; width: 100%; max-width: 100%; min-width: 0; box-sizing: border-box; padding: 10px 12px; line-height: 1.45; border-radius: 8px; resize: vertical; overflow: auto; }
    #ytUrls { min-height: 112px; max-height: 260px; }
    #ytCookiesText { min-height: 88px; max-height: 220px; }
    #onlineAlbumIdeaUrls { min-height: 112px; max-height: 260px; }
    .lab-online-idea .idea-tag { display: inline-block; padding: 3px 8px; border-radius: 999px; background: rgba(8,169,128,.16); color: #43d9b0; font-size: .75rem; font-weight: 700; }
    .lab-online-idea .online-track-results { max-height: 190px; overflow-y: auto; overflow-x: hidden; border: 1px solid #283448; border-radius: 8px; margin: 10px 0; }
    .lab-online-idea .online-track-row { display: flex; align-items: flex-start; gap: 9px; padding: 9px 10px; border-bottom: 1px solid #283448; overflow-wrap: anywhere; }
    .lab-online-idea .online-track-row:last-child { border-bottom: 0; }
    .lab-online-idea .online-track-row small { display: block; color: #a8b9d2; margin-top: 3px; }
    .lab-online-idea .online-track-row input { margin-top: 3px; flex: 0 0 auto; }
    .lab-online-idea .online-status { min-height: 1.4em; }
    @media (max-width: 950px) {
      .lab-tracks-table, .lab-tracks-table tbody, .lab-tracks-table tr, .lab-tracks-table td { display: block; width: 100%; box-sizing: border-box; }
      .lab-tracks-table thead { display: none; }
      .lab-tracks-table tr { border: 1px solid #354258; border-radius: 9px; margin: 0 0 12px; padding: 8px; }
      .lab-tracks-table td { border: 0; padding: 5px 7px; }
      .lab-track-number { color: #a8b9d2; }
      .lab-track-path code { max-width: 100%; }
      .lab-track-buttons { max-width: 420px; }
    }
    @media (max-width: 760px) {
      .lab-album-layout { grid-template-columns: minmax(0, 1fr); }
      .lab-album-layout #albumMetaCard,
      .lab-album-layout #onlineAlbumCard,
      .lab-album-layout #youtubeImportCard,
      .lab-album-layout #addTracksForm,
      .lab-album-layout .album-cover-card { grid-column: 1; grid-row: auto; }
      .lab-album-layout #albumMetaCard { order: 1; }
      .lab-album-layout #onlineAlbumCard { order: 2; }
      .lab-album-layout #youtubeImportCard { order: 3; min-height: 0; }
      .lab-album-layout #addTracksForm { order: 4; min-height: 0; }
      .lab-album-layout .album-cover-card { order: 5; }
      .album-cover-card .cover-editor-layout { grid-template-columns: minmax(0, 1fr); gap: 18px; }
      .album-cover-card .cover-preview { width: min(180px, 100%); }
      .album-cover-card .cover-controls { max-width: none; }
    }
  </style>
</head>
<body>
  <header class="lab-header">
    <div>
      <h1><?= $album ? lab_h($album['title']) : 'Nuevo álbum' ?></h1>
      <p class="muted">Pistas bajo <code>hls_out/{id}_{album}/</code></p>
    </div>
    <div class="header-actions">
      <?php if ($album && $trackCount > 0): ?>
        <a class="btn primary btn-lg" id="playAlbumBtn" href="player.php?album=<?= lab_h(urlencode($album['id'])) ?>">▶ Reproducir álbum</a>
      <?php endif; ?>
      <a class="btn" href="index.php?view=library">Biblioteca</a>
      <a class="btn" href="index.php">Inicio</a>
    </div>
  </header>
  <main class="lab-main">
    <?php if ($err): ?><div class="banner err"><?= lab_h($err) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="banner ok"><?= lab_h($ok) ?></div><?php endif; ?>

    <div id="progressPanel" class="card progress-panel" hidden>
      <h2>Convirtiendo a HLS…</h2>
      <div class="progress-ring-wrap">
        <svg class="progress-ring" width="120" height="120" viewBox="0 0 120 120">
          <circle class="progress-ring-bg" cx="60" cy="60" r="52" />
          <circle class="progress-ring-fg" id="progressRingFg" cx="60" cy="60" r="52" />
        </svg>
        <div class="progress-ring-label" id="progressPct">0%</div>
      </div>
      <p class="muted" id="progressDetail">Preparando…</p>
      <ul class="progress-log" id="progressLog"></ul>
    </div>

    <?php if (!$album): ?>
    <form class="card" id="createForm">
      <h2>Crear álbum y añadir pistas</h2>
      <p class="muted">Elige título y uno o varios archivos. Verás el anillo de progreso (0–100%) mientras se convierte cada pista.</p>
      <label>Título del álbum *</label>
      <input type="text" id="newTitle" name="title" required placeholder="Ej. Porfiado 2012" value="">
      <label>Artista (opcional)</label>
      <input type="text" id="newArtist" name="artist" placeholder="Artista" value="">
      <label>Archivos de audio (varios)</label>
      <input type="file" id="newFiles" accept="<?= lab_h($accept) ?>" multiple>
      <p class="muted">Formatos: <?= lab_h(implode(', ', $cfg['allowed_extensions'])) ?>. Máx. <?= (int)$cfg['max_upload_mib'] ?> MiB c/u.</p>
      <div class="header-actions">
        <button class="btn primary btn-lg" type="submit">Crear álbum y convertir archivos</button>
        <button class="btn btn-lg" type="button" id="createYoutubeAlbumBtn">Crear álbum y añadir desde YouTube</button>
      </div>
    </form>
    <?php else: ?>

    <?php if ($trackCount > 0): ?>
    <div class="card play-cta">
      <p><strong><?= $trackCount ?></strong> pista(s) listas en este álbum.</p>
      <a class="btn primary btn-lg" href="player.php?album=<?= lab_h(urlencode($album['id'])) ?>">▶ Reproducir álbum</a>
      <a class="btn" href="index.php?view=library">Ver en biblioteca</a>
    </div>
    <?php endif; ?>

    <div class="lab-album-layout">
    <form class="card highlight-card" id="albumMetaCard" method="post">
      <input type="hidden" name="action" value="save_meta">
      <h2>Datos del álbum</h2>
      <label>Título</label>
      <input type="text" name="title" required value="<?= lab_h($album['title'] ?? '') ?>">
      <label>Artista</label>
      <input type="text" name="artist" value="<?= lab_h($album['artist'] ?? '') ?>">
      <button class="btn primary" type="submit">Guardar datos</button>
    </form>

    <section class="card lab-online-idea" id="onlineAlbumCard" aria-labelledby="onlineAlbumIdeaTitle">
      <span class="idea-tag">Álbum virtual · sin descargar</span>
      <h2 id="onlineAlbumIdeaTitle">Crear álbum desde enlaces de YouTube Music</h2>
      <p class="muted">Pega hasta 10 enlaces de canciones, uno por línea. Se inspeccionan antes de crear el álbum; no se descargan ni se convierten archivos.</p>
      <label for="onlineAlbumTitle">Título del álbum (opcional)</label>
      <input id="onlineAlbumTitle" type="text" maxlength="160" placeholder="Ej. Favoritas de YouTube Music">
      <label for="onlineAlbumArtist">Artista (opcional)</label>
      <input id="onlineAlbumArtist" type="text" maxlength="160" placeholder="Ej. Varios artistas">
      <label for="onlineAlbumIdeaUrls">Enlaces de YouTube Music / YouTube</label>
      <textarea id="onlineAlbumIdeaUrls" rows="4" placeholder="https://music.youtube.com/watch?v=…&#10;https://music.youtube.com/watch?v=…"></textarea>
      <div class="header-actions" style="margin-top:.75rem">
        <button class="btn" type="button" id="onlineAlbumInspectBtn">Inspeccionar enlaces</button>
        <button class="btn primary" type="button" id="onlineAlbumCreateBtn" disabled>Crear álbum de enlaces</button>
      </div>
      <div id="onlineAlbumStatus" class="muted online-status" role="status" aria-live="polite"></div>
      <div id="onlineAlbumResults" class="online-track-results" hidden></div>
      <p class="muted">La reproducción se resuelve bajo demanda con yt-dlp y depende de que YouTube permita acceder al video. Los anuncios, restricciones regionales, bloqueos o caducidad de enlaces no se pueden controlar desde Music Lab. Este modo no ofrece HLS adaptativo ni garantiza disponibilidad permanente.</p>
    </section>

    <?php if (!$isOnlineAlbum): ?>
    <form class="card highlight-card" id="addTracksForm">
      <h2>Añadir pistas (archivos source)</h2>
      <p class="muted">Se guardan en <code>hls_out/<?= lab_h($album['id']) ?>_…/</code></p>
      <input type="file" id="addFiles" accept="<?= lab_h($accept) ?>" multiple required>
      <button class="btn primary btn-lg" type="submit">Subir y convertir a HLS</button>
    </form>

    <section class="card highlight-card" id="youtubeImportCard">
      <h2>Importar desde YouTube</h2>
      <p class="muted">Pega hasta 10 enlaces de videos individuales, uno por línea. Primero se inspeccionan sin descargar; después podrás excluir videos y editar el nombre de salida.</p>
      <label for="ytUrls">Enlaces de YouTube</label>
      <textarea id="ytUrls" rows="5" placeholder="https://www.youtube.com/watch?v=…&#10;https://youtu.be/…"></textarea>
      <details style="margin:.75rem 0">
        <summary>Cookies opcionales (videos que requieren sesión)</summary>
        <p class="muted">Usa un archivo <code>cookies.txt</code> exportado en formato Netscape. Las cookies se guardan temporalmente durante esta petición y se eliminan al terminar; no se guardan en el álbum. Trátalas como una contraseña.</p>
        <label for="ytCookiesFile">Subir cookies.txt</label>
        <input type="file" id="ytCookiesFile" accept=".txt,text/plain">
        <label for="ytCookiesText">O pegar el contenido de cookies.txt</label>
        <textarea id="ytCookiesText" rows="3" placeholder="# Netscape HTTP Cookie File&#10;…"></textarea>
      </details>
      <div class="header-actions">
        <button class="btn primary" type="button" id="ytInspectBtn">Inspeccionar enlaces</button>
        <label for="ytOutputFormat" style="margin:0">Formato de salida</label>
        <select id="ytOutputFormat"><option value="opus">Opus (recomendado si el origen lo permite)</option><option value="mp3">MP3</option></select>
      </div>
      <p class="muted">Opus suele conservar mejor el audio cuando YouTube ofrece Opus; convertir a MP3 no mejora la calidad del origen y puede volver a comprimirlo.</p>
      <div id="ytInspectStatus" class="muted" role="status" aria-live="polite"></div>
      <div id="ytResults" class="job-list" style="margin-top:1rem"></div>
      <button class="btn primary btn-lg" type="button" id="ytImportBtn" disabled>Descargar e importar seleccionadas</button>
      <div id="ytImportStatus" class="muted" role="status" aria-live="polite"></div>
    </section>
    <?php else: ?>
    <section class="card"><h2>Modo de álbum virtual</h2><p class="muted">Este álbum conserva enlaces externos y no crea archivos HLS. Para convertir canciones a HLS, crea un álbum local independiente.</p></section>
    <?php endif; ?>

    <form class="card album-cover-card" method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="cover">
      <h2>Portada y encuadre</h2>
      <div class="cover-editor-layout">
        <div class="cover-image-column">
          <div class="cover-preview" id="coverPreview" style="<?php
            if ($coverUrl) {
              echo 'background-image:url(' . lab_h($coverUrl) . ');';
              echo 'background-position:' . (float)$layout['x'] . '% ' . (float)$layout['y'] . '%;';
              echo 'background-size:' . (float)$layout['zoom'] . '%;';
            }
          ?>"><?php if (!$coverUrl): ?><span>Sin portada</span><?php endif; ?></div>
          <label class="cover-file-label" for="coverImageFile">Imagen</label>
          <input class="cover-file-input" id="coverImageFile" type="file" name="cover" accept="image/*">
        </div>
        <div class="cover-controls">
          <div class="cover-control">
            <label for="coverX">Horizontal</label>
            <input id="coverX" type="range" name="cover_x" min="0" max="100" value="<?= (int)$layout['x'] ?>" oninput="updCover()">
          </div>
          <div class="cover-control">
            <label for="coverY">Vertical</label>
            <input id="coverY" type="range" name="cover_y" min="0" max="100" value="<?= (int)$layout['y'] ?>" oninput="updCover()">
          </div>
          <div class="cover-control">
            <label for="coverZoom">Zoom</label>
            <input id="coverZoom" type="range" name="cover_zoom" min="50" max="200" value="<?= (int)$layout['zoom'] ?>" oninput="updCover()">
          </div>
          <button class="btn primary cover-save" type="submit">Guardar portada</button>
        </div>
      </div>
    </form>
    <script>
      function updCover(){
        const p=document.getElementById('coverPreview'); if(!p)return;
        p.style.backgroundPosition=(document.querySelector('[name=cover_x]')?.value||50)+'% '+(document.querySelector('[name=cover_y]')?.value||50)+'%';
        p.style.backgroundSize=(document.querySelector('[name=cover_zoom]')?.value||100)+'%';
      }
    </script>
    </div><!-- /.lab-album-layout -->

    <section class="card lab-tracks-card">
      <h2>Pistas (<?= $trackCount ?>)</h2>
      <?php if ($trackCount === 0): ?>
        <p class="muted">Aún no hay pistas. Usa <strong>Añadir pistas</strong>.</p>
      <?php else: ?>
      <div class="lab-tracks-scroll">
        <table class="lab-tracks-table">
          <thead><tr><th scope="col">#</th><th scope="col">Pista</th><th scope="col"><?= $isOnlineAlbum ? 'Duración' : 'Calidad' ?></th><th scope="col"><?= $isOnlineAlbum ? 'Enlace de origen' : 'Ruta HLS' ?></th><th scope="col">Acciones</th></tr></thead>
          <tbody>
          <?php foreach ($album['tracks'] as $i => $t): ?>
          <tr>
            <td class="lab-track-number" data-label="#"><?= $i + 1 ?></td>
            <td class="lab-track-name" data-label="Pista"><strong><?= lab_h($t['name'] ?? '') ?></strong></td>
            <td class="lab-track-quality" data-label="<?= $isOnlineAlbum ? 'Duración' : 'Calidad' ?>">
              <?php if ($isOnlineAlbum): ?><span><?= (int)($t['duration'] ?? 0) > 0 ? gmdate(((int)$t['duration'] >= 3600 ? 'H:i:s' : 'i:s'), (int)$t['duration']) : '—' ?></span>
              <?php else: ?><span>~<?= (int)($t['source_kbps'] ?? 0) ?> kbps</span><small><?= lab_h(implode(', ', $t['renditions'] ?? [])) ?></small><?php endif; ?>
            </td>
            <td class="lab-track-path" data-label="<?= $isOnlineAlbum ? 'Enlace de origen' : 'Ruta HLS' ?>"><code title="<?= lab_h($isOnlineAlbum ? ($t['source_url'] ?? '') : ($t['hls'] ?? '')) ?>"><?= lab_h($isOnlineAlbum ? ($t['source_url'] ?? '') : ($t['hls'] ?? '')) ?></code></td>
            <td class="lab-track-actions" data-label="Acciones">
              <div class="lab-track-buttons">
                <a class="lab-icon-btn lab-btn-play" title="Reproducir pista" aria-label="Reproducir <?= lab_h($t['name'] ?? '') ?>" href="player.php?album=<?= lab_h(urlencode($album['id'])) ?>&i=<?= $i ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 5 12 7-12 7z" fill="currentColor" stroke="none"/></svg></a>
                <?php if (!$isOnlineAlbum): ?>
                <form method="post" onsubmit="return confirm('¿Reconstruir source?');">
                  <input type="hidden" name="action" value="reconstruct">
                  <input type="hidden" name="job_id" value="<?= lab_h($t['job_id'] ?? '') ?>">
                  <button class="lab-icon-btn lab-btn-rebuild" type="submit" title="Reconstruir source" aria-label="Reconstruir source"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12V9a3 3 0 0 1 3-3h13m-3-3 3 3-3 3M20 12v3a3 3 0 0 1-3 3H4m3 3-3-3 3-3M10 10l-2 2 2 2m4-4 2 2-2 2"/></svg><span>Reconstruir</span></button>
                </form>
                <button class="lab-icon-btn lab-btn-edit rename-track-btn" type="button" title="Editar nombre" data-track-id="<?= lab_h((string)($t['id'] ?? '')) ?>" data-track-name="<?= lab_h((string)($t['name'] ?? '')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><span>Editar</span></button>
                <button class="lab-icon-btn lab-btn-delete delete-track-btn" type="button" title="Eliminar pista" data-track-id="<?= lab_h((string)($t['id'] ?? '')) ?>" data-track-name="<?= lab_h((string)($t['name'] ?? 'esta pista')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11l4 4m0-4-4 4"/></svg><span>Eliminar</span></button>
                <?php else: ?><span class="muted">Enlace externo</span><?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </main>

  <script>
  (() => {
    const RING_C = 2 * Math.PI * 52;
    const panel = document.getElementById('progressPanel');
    const fg = document.getElementById('progressRingFg');
    const pctEl = document.getElementById('progressPct');
    const detail = document.getElementById('progressDetail');
    const logEl = document.getElementById('progressLog');
    if (fg) {
      fg.style.strokeDasharray = String(RING_C);
      fg.style.strokeDashoffset = String(RING_C);
    }
    function setProgress(pct, text) {
      pct = Math.max(0, Math.min(100, pct));
      if (pctEl) pctEl.textContent = Math.round(pct) + '%';
      if (fg) fg.style.strokeDashoffset = String(RING_C * (1 - pct / 100));
      if (detail && text) detail.textContent = text;
    }
    function logLine(msg, ok) {
      if (!logEl) return;
      const li = document.createElement('li');
      li.textContent = msg;
      if (ok === true) li.className = 'ok';
      if (ok === false) li.className = 'err';
      logEl.appendChild(li);
    }

    async function convertFiles(albumId, fileList) {
      const files = Array.from(fileList || []).filter(Boolean);
      if (!files.length) throw new Error('Selecciona al menos un archivo');
      panel.hidden = false;
      logEl.innerHTML = '';
      setProgress(0, 'Iniciando…');
      let done = 0;
      for (const file of files) {
        setProgress((done / files.length) * 100, `Convirtiendo ${done + 1}/${files.length}: ${file.name}`);
        const fd = new FormData();
        fd.append('album_id', albumId);
        fd.append('audio', file, file.name);
        const r = await fetch('api_convert.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const raw = await r.text();
        let j = {};
        try { j = JSON.parse(raw); } catch { j = { error: raw.slice(0, 200) }; }
        if (!r.ok || j.error) {
          logLine('Error: ' + file.name + ' — ' + (j.error || r.status), false);
          throw new Error(j.error || ('Falló ' + file.name));
        }
        done++;
        logLine('OK ' + file.name + ' → ' + (j.track?.renditions || []).join(', '), true);
        setProgress((done / files.length) * 100, `Listo ${done}/${files.length}`);
      }
      setProgress(100, 'Conversión terminada');
      return done;
    }

    const createForm = document.getElementById('createForm');
    if (createForm) {
      createForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const title = document.getElementById('newTitle').value.trim();
        const artist = document.getElementById('newArtist').value.trim();
        const files = document.getElementById('newFiles').files;
        if (!title) return alert('Indica un título');
        if (!files?.length) return alert('Selecciona uno o más archivos de audio o usa «Crear álbum y añadir desde YouTube».');
        createForm.querySelector('button').disabled = true;
        try {
          const fd = new FormData();
          fd.append('title', title);
          fd.append('artist', artist);
          const r = await fetch('api_album.php', { method: 'POST', body: fd, credentials: 'same-origin' });
          const raw = await r.text();
          let j;
          try { j = JSON.parse(raw); } catch { throw new Error('api_album no devolvió JSON: ' + raw.slice(0, 180)); }
          if (!r.ok || j.error) throw new Error(j.error || 'No se pudo crear el álbum');
          const albumId = j.album_id || j.album?.id;
          if (!albumId) throw new Error('La API no devolvió album_id');
          await convertFiles(albumId, files);
          // Portada automática (iTunes → Deezer → MusicBrainz)
          try {
            const title = document.getElementById('newTitle')?.value?.trim() || '';
            const artist = document.getElementById('newArtist')?.value?.trim() || '';
            const cq = new URLSearchParams({ artist, album: title, title, mode: 'one' });
            const cr = await fetch('api_cover.php?' + cq.toString(), { credentials: 'same-origin' });
            const cj = await cr.json().catch(() => ({}));
            if (cj.cover) {
              const fd2 = new FormData();
              fd2.append('action', 'set_cover_url');
              fd2.append('album_id', albumId);
              fd2.append('cover_url', cj.cover);
              fd2.append('matched_album', cj.album || title);
              fd2.append('matched_artist', artist);
              await fetch('api_album_manage.php', { method: 'POST', body: fd2, credentials: 'same-origin' });
            }
          } catch (_) {}
          window.location.href = 'album_edit.php?id=' + encodeURIComponent(albumId) + '&created=1';
        } catch (err) {
          alert(err.message || String(err));
          createForm.querySelector('button').disabled = false;
        }
      });
    }

    const createYoutubeAlbumBtn = document.getElementById('createYoutubeAlbumBtn');
    if (createYoutubeAlbumBtn) {
      createYoutubeAlbumBtn.addEventListener('click', async () => {
        const title = document.getElementById('newTitle')?.value.trim() || '';
        const artist = document.getElementById('newArtist')?.value.trim() || '';
        if (!title) { alert('Indica el título del álbum.'); return; }
        createYoutubeAlbumBtn.disabled = true;
        createYoutubeAlbumBtn.textContent = 'Creando álbum…';
        try {
          const fd = new FormData();
          fd.append('title', title);
          fd.append('artist', artist);
          const response = await fetch('api_album.php', {method:'POST', body:fd, credentials:'same-origin', headers:{'Accept':'application/json'}});
          const result = await response.json().catch(() => ({}));
          if (!response.ok || result.error) throw new Error(result.error || 'No se pudo crear el álbum.');
          const newId = result.album_id || result.album?.id;
          if (!newId) throw new Error('La API no devolvió el identificador del álbum.');
          window.location.href = 'album_edit.php?id=' + encodeURIComponent(newId) + '&youtube=1';
        } catch (error) {
          alert(error.message || 'No se pudo crear el álbum.');
          createYoutubeAlbumBtn.disabled = false;
          createYoutubeAlbumBtn.textContent = 'Crear álbum y añadir desde YouTube';
        }
      });
    }

    document.querySelectorAll('.rename-track-btn').forEach((button) => {
      button.addEventListener('click', async () => {
        const trackId = button.dataset.trackId || '';
        const currentName = button.dataset.trackName || '';
        if (!trackId) {
          alert('No se encontró el identificador de la pista.');
          return;
        }

        const newName = window.prompt('Escribe el nuevo nombre de la pista:', currentName);
        if (newName === null) return;
        if (!newName.trim()) {
          alert('El nombre no puede estar vacío.');
          return;
        }
        if (!albumId) {
          alert('No se encontró el álbum.');
          return;
        }

        button.disabled = true;
        const oldText = button.textContent;
        button.textContent = 'Guardando…';

        try {
          const fd = new FormData();
          fd.append('action', 'rename_track');
          fd.append('album_id', albumId);
          fd.append('track_id', trackId);
          fd.append('name', newName);

          const response = await fetch('api_album_manage.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
          });
          const result = await response.json().catch(() => ({}));

          if (!response.ok || !result.success) {
            throw new Error(result.error || 'No se pudo cambiar el nombre.');
          }
          if (result.warning) alert(result.warning);
          window.location.reload();
        } catch (error) {
          alert(error.message || 'Error al cambiar el nombre.');
          button.disabled = false;
          button.textContent = oldText;
        }
      });
    });

    document.querySelectorAll('.delete-track-btn').forEach((button) => {
      button.addEventListener('click', async () => {
        const trackId = button.dataset.trackId || '';
        const trackName = button.dataset.trackName || 'esta pista';
        if (!trackId) { alert('No se encontró el identificador de la pista.'); return; }
        if (!window.confirm('¿Eliminar definitivamente la pista "' + trackName + '" de este álbum? Se quitará de la biblioteca y se intentarán borrar sus archivos HLS.')) return;
        button.disabled = true;
        const oldText = button.textContent;
        button.textContent = 'Eliminando…';
        try {
          const fd = new FormData();
          fd.append('action', 'delete_track');
          fd.append('album_id', albumId);
          fd.append('track_id', trackId);
          const response = await fetch('api_album_manage.php', {
            method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}
          });
          const result = await response.json().catch(() => ({}));
          if (!response.ok || !result.success) throw new Error(result.error || 'No se pudo eliminar la pista.');
          if (result.warning) alert(result.warning);
          window.location.reload();
        } catch (error) {
          alert(error.message || 'Error al eliminar la pista.');
          button.disabled = false;
          button.textContent = oldText;
        }
      });
    });

    const addForm = document.getElementById('addTracksForm');
    const albumId = <?= $album ? json_encode($album['id']) : 'null' ?>;

    // Importador de YouTube: inspección previa y confirmación de cada pista.
    const ytUrls = document.getElementById('ytUrls');
    const ytInspectBtn = document.getElementById('ytInspectBtn');
    const ytImportBtn = document.getElementById('ytImportBtn');
    const ytResults = document.getElementById('ytResults');
    const ytInspectStatus = document.getElementById('ytInspectStatus');
    const ytImportStatus = document.getElementById('ytImportStatus');
    const ytFormat = document.getElementById('ytOutputFormat');
    const ytCookieFile = document.getElementById('ytCookiesFile');
    const ytCookieText = document.getElementById('ytCookiesText');
    let ytInspected = [];

    function ytAppendCookies(fd) {
      if (ytCookieText && ytCookieText.value.trim()) fd.append('cookies_text', ytCookieText.value);
      if (ytCookieFile && ytCookieFile.files && ytCookieFile.files[0]) fd.append('cookies_file', ytCookieFile.files[0]);
    }
    function ytSafeBase(value) {
      return String(value || 'Pista').replace(/[\\/\x00-\x1F:*?"<>|]+/g, '_').replace(/\s+/g, ' ').trim().replace(/[ .]+$/g, '').slice(0, 160) || 'Pista';
    }
    function ytCurrentRows() {
      return Array.from(ytResults.querySelectorAll('.youtube-track-row')).filter(row => row.querySelector('.yt-include') && row.querySelector('.yt-filename'));
    }
    function ytRenderResults(results) {
      ytResults.replaceChildren();
      ytInspected = results;
      for (const item of results) {
        const row = document.createElement('div');
        row.className = 'youtube-track-row';
        row.style.cssText = 'padding:.85rem 0;border-bottom:1px solid var(--border,#ddd)';
        if (!item.ok) {
          const error = document.createElement('p');
          error.className = 'err';
          error.textContent = (item.url || 'Enlace') + ' — ' + (item.error || 'No se pudo inspeccionar');
          row.appendChild(error);
          ytResults.appendChild(row);
          continue;
        }
        row.dataset.url = item.webpage_url || item.url;
        row.dataset.title = item.title || 'Pista';
        const top = document.createElement('div');
        top.style.cssText = 'display:flex;gap:.65rem;align-items:flex-start;flex-wrap:wrap';
        const includeLabel = document.createElement('label');
        includeLabel.style.cssText = 'display:flex;gap:.45rem;align-items:center;flex:0 0 auto';
        const include = document.createElement('input');
        include.type = 'checkbox'; include.className = 'yt-include'; include.checked = true;
        includeLabel.append(include, document.createTextNode('Incluir'));
        const title = document.createElement('strong');
        title.textContent = item.title || 'Pista';
        title.style.flex = '1 1 220px';
        top.append(includeLabel, title);
        row.appendChild(top);
        const meta = document.createElement('p');
        meta.className = 'muted';
        const formats = (item.formats || []).map(f => [f.codec, f.ext, f.kbps ? f.kbps + ' kbps' : 'bitrate n/d', f.sample_rate ? Math.round(f.sample_rate / 1000) + ' kHz' : ''].filter(Boolean).join(' · '));
        meta.textContent = 'Duración: ' + (item.duration_text || 'Desconocida') + (item.uploader ? ' · Canal: ' + item.uploader : '') + '\nAudio disponible: ' + (formats.length ? formats.join(' | ') : 'yt-dlp no informó formatos de audio');
        meta.style.whiteSpace = 'pre-wrap';
        row.appendChild(meta);
        const nameLabel = document.createElement('label');
        nameLabel.textContent = 'Nombre del archivo de salida';
        const name = document.createElement('input');
        name.type = 'text'; name.className = 'yt-filename'; name.required = true;
        name.value = ytSafeBase(item.title) + '.' + ytFormat.value;
        nameLabel.appendChild(name);
        row.appendChild(nameLabel);
        ytResults.appendChild(row);
      }
      ytImportBtn.disabled = !ytCurrentRows().length;
    }
    if (ytInspectBtn && albumId) {
      ytInspectBtn.addEventListener('click', async () => {
        ytInspectBtn.disabled = true; ytImportBtn.disabled = true; ytInspectStatus.textContent = 'Consultando metadatos y formatos disponibles…'; ytImportStatus.textContent = '';
        const fd = new FormData(); fd.append('action', 'inspect'); fd.append('urls', ytUrls.value); ytAppendCookies(fd);
        try {
          const response = await fetch('api_youtube.php', {method:'POST', body:fd, credentials:'same-origin', headers:{'Accept':'application/json'}});
          const result = await response.json().catch(() => ({}));
          if (!response.ok || result.error) throw new Error(result.error || 'No se pudieron inspeccionar los enlaces.');
          ytRenderResults(result.results || []);
          const good = (result.results || []).filter(x => x.ok).length;
          ytInspectStatus.textContent = `Inspección terminada: ${good} enlace(s) válido(s) de ${(result.results || []).length}. Revisa la lista antes de importar.`;
        } catch (error) { ytInspectStatus.textContent = error.message || 'Error de inspección.'; }
        finally { ytInspectBtn.disabled = false; }
      });
    }
    if (ytFormat) ytFormat.addEventListener('change', () => {
      ytCurrentRows().forEach(row => {
        const input = row.querySelector('.yt-filename');
        if (!input) return;
        const base = input.value.replace(/\.(?:mp3|opus)$/i, '');
        input.value = ytSafeBase(base) + '.' + ytFormat.value;
      });
    });
    if (ytImportBtn && albumId) {
      ytImportBtn.addEventListener('click', async () => {
        const tracks = ytCurrentRows().filter(row => row.querySelector('.yt-include')?.checked).map(row => ({
          url: row.dataset.url,
          title: row.dataset.title,
          filename: row.querySelector('.yt-filename')?.value.trim() || ''
        }));
        if (!tracks.length) { alert('Selecciona al menos una pista.'); return; }
        if (tracks.some(t => !t.filename)) { alert('Todos los archivos deben tener nombre.'); return; }

        ytImportBtn.disabled = true;
        ytInspectBtn.disabled = true;
        ytImportStatus.replaceChildren();
        if (panel) {
          panel.hidden = false;
          // Llevar el indicador al área visible al comenzar la importación.
          requestAnimationFrame(() => panel.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        }
        if (logEl) logEl.replaceChildren();
        const panelTitle = panel?.querySelector('h2');
        if (panelTitle) panelTitle.textContent = 'Importando desde YouTube…';
        setProgress(0, `Preparando ${tracks.length} pista(s)…`);
        let imported = 0;
        let failed = 0;
        const results = [];
        try {
          // Una petición por pista: cada resultado queda visible y un fallo no oculta
          // los resultados anteriores ni impide procesar las siguientes pistas.
          for (let i = 0; i < tracks.length; i++) {
            const track = tracks[i];
            setProgress((i / tracks.length) * 100, `Procesando ${i + 1}/${tracks.length}: ${track.filename}`);
            const fd = new FormData();
            fd.append('action', 'import');
            fd.append('album_id', albumId);
            fd.append('output_format', ytFormat.value);
            fd.append('tracks', JSON.stringify([track]));
            ytAppendCookies(fd);
            try {
              const response = await fetch('api_youtube.php', {method:'POST', body:fd, credentials:'same-origin', headers:{'Accept':'application/json'}});
              const result = await response.json().catch(() => ({}));
              if (!response.ok || result.error) throw new Error(result.error || `HTTP ${response.status}`);
              const item = (result.results || [])[0];
              if (!item || !item.ok) throw new Error(item?.error || 'El servidor no confirmó que se añadiera la pista al álbum.');
              imported++;
              const message = `OK ${i + 1}/${tracks.length}: ${item.title || track.filename}`;
              logLine(message, true);
              results.push(message);
            } catch (error) {
              failed++;
              const message = `ERROR ${i + 1}/${tracks.length}: ${track.filename} — ${error.message || 'falló la importación'}`;
              logLine(message, false);
              results.push(message);
            }
            setProgress(((i + 1) / tracks.length) * 100, `Terminadas ${i + 1}/${tracks.length}; correctas: ${imported}; fallidas: ${failed}`);
          }
          setProgress(100, `Importación finalizada: ${imported} correcta(s), ${failed} fallida(s).`);
          const summary = document.createElement('p');
          summary.className = failed ? 'err' : 'ok';
          summary.textContent = `Importación finalizada: ${imported} correcta(s), ${failed} fallida(s).`;
          ytImportStatus.appendChild(summary);
          const reload = document.createElement('button');
          reload.type = 'button';
          reload.className = 'btn primary';
          reload.textContent = 'Actualizar álbum';
          reload.addEventListener('click', () => window.location.reload());
          ytImportStatus.appendChild(reload);
          if (failed) {
            const details = document.createElement('pre');
            details.style.whiteSpace = 'pre-wrap';
            details.textContent = results.filter(x => x.startsWith('ERROR')).join('\n');
            ytImportStatus.appendChild(details);
          }
        } finally {
          ytImportBtn.disabled = false;
          ytInspectBtn.disabled = false;
          if (panelTitle) panelTitle.textContent = 'Convirtiendo a HLS…';
        }
      });
    }
    if (addForm && albumId) {
      addForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const files = document.getElementById('addFiles').files;
        addForm.querySelector('button').disabled = true;
        try {
          await convertFiles(albumId, files);
          window.location.href = 'album_edit.php?id=' + encodeURIComponent(albumId) + '&added=1';
        } catch (err) {
          alert(err.message || String(err));
          addForm.querySelector('button').disabled = false;
        }
      });
    }
  })();
  </script>
<script>
(() => {
  const urls = document.getElementById('onlineAlbumIdeaUrls');
  const inspectBtn = document.getElementById('onlineAlbumInspectBtn');
  const createBtn = document.getElementById('onlineAlbumCreateBtn');
  const resultsBox = document.getElementById('onlineAlbumResults');
  const status = document.getElementById('onlineAlbumStatus');
  if (!urls || !inspectBtn || !createBtn || !resultsBox || !status) return;
  let inspected = [];
  const selected = () => Array.from(resultsBox.querySelectorAll('input[type="checkbox"]:checked'))
    .map(el => inspected[Number(el.dataset.index)]).filter(item => item && item.ok);
  const setBusy = (busy, message) => {
    inspectBtn.disabled = busy;
    createBtn.disabled = busy || selected().length === 0;
    status.textContent = message || '';
  };
  const post = async (fields) => {
    const fd = new FormData();
    Object.entries(fields).forEach(([k,v]) => fd.append(k, typeof v === 'string' ? v : JSON.stringify(v)));
    const response = await fetch('api_youtube.php', {method:'POST', body:fd, credentials:'same-origin', headers:{'Accept':'application/json'}});
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.error) throw new Error(data.error || `Error HTTP ${response.status}`);
    return data;
  };
  inspectBtn.addEventListener('click', async () => {
    inspected = [];
    resultsBox.replaceChildren(); resultsBox.hidden = true;
    createBtn.disabled = true;
    const raw = urls.value.trim();
    if (!raw) { status.textContent = 'Pega al menos un enlace.'; return; }
    setBusy(true, 'Inspeccionando enlaces sin descargar audio…');
    try {
      const data = await post({action:'inspect', urls:raw});
      inspected = Array.isArray(data.results) ? data.results : [];
      inspected.forEach((item, i) => {
        const row = document.createElement('label'); row.className = 'online-track-row';
        const check = document.createElement('input'); check.type = 'checkbox'; check.dataset.index = String(i); check.checked = !!item.ok; check.disabled = !item.ok;
        check.addEventListener('change', () => { createBtn.disabled = selected().length === 0; });
        const body = document.createElement('span');
        const title = document.createElement('strong'); title.textContent = item.ok ? (item.title || 'Pista sin título') : 'No se pudo inspeccionar este enlace';
        body.appendChild(title);
        const meta = document.createElement('small');
        meta.textContent = item.ok ? `${item.uploader || 'Artista/canal desconocido'} · ${item.duration_text || 'duración desconocida'}` : (item.error || 'Error de inspección');
        body.appendChild(meta);
        row.append(check, body); resultsBox.appendChild(row);
      });
      resultsBox.hidden = false;
      const count = inspected.filter(item => item.ok).length;
      setBusy(false, `Inspección terminada: ${count} de ${inspected.length} enlace(s) disponibles. Selecciona las pistas que quieres incluir.`);
    } catch (e) { setBusy(false, e.message || 'No se pudieron inspeccionar los enlaces.'); }
  });
  createBtn.addEventListener('click', async () => {
    const tracks = selected().map(item => ({url:item.webpage_url || item.url, title:item.title, duration:item.duration}));
    if (!tracks.length) { status.textContent = 'Selecciona al menos una pista válida.'; return; }
    setBusy(true, `Creando álbum virtual con ${tracks.length} pista(s); no se descargará audio…`);
    try {
      const data = await post({action:'create_online_album', title:document.getElementById('onlineAlbumTitle')?.value || '', artist:document.getElementById('onlineAlbumArtist')?.value || '', tracks});
      status.textContent = 'Álbum virtual creado. Abriendo editor…';
      window.location.href = 'album_edit.php?id=' + encodeURIComponent(data.album_id);
    } catch (e) { setBusy(false, e.message || 'No se pudo crear el álbum.'); }
  });
})();
</script>

<script src="assets/shell-player.js"></script>
<link rel="stylesheet" href="assets/player.css">
</body>
</html>