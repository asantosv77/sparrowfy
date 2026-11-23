<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
lab_auth_require_album_manager();
require_once __DIR__ . '/lib/user_preferences.php';
$cfg = lab_load_config();
$sfMemUser = lab_user_client_memory((string)(lab_auth_current_account()['id'] ?? ''));
$sfMemEffective = $sfMemUser > 0 ? $sfMemUser : (int)($cfg['client_memory_mib'] ?? 0);
$sfTheme = lab_user_theme((string)(lab_auth_current_account()['id'] ?? ''));
$id = isset($_GET['id']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['id']) : '';
$album = $id ? lab_load_album($id) : null;
$err = '';
$ok = '';
$accept = implode(',', array_map(static fn ($e) => '.' . $e, lab_allowed_audio_extensions($cfg)));

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
            $rec = lab_reconstruct_source($job);
            $ok = 'Fuente reconstruida temporalmente para el job ' . $job . ' (' . basename((string) ($rec['reconstructed_path'] ?? $rec['reconstructed'] ?? 'audio')) . ').';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
        if ($id) $album = lab_load_album($id);
    }
    if ($id) $album = lab_load_album($id);
}

require_once __DIR__.'/lib/track_reuse.php';
require_once __DIR__.'/lib/cover_url.php';
if($album)$album=sf_reuse_view($album);
$layout = $album['cover_layout'] ?? ['x' => 50, 'y' => 50, 'zoom' => 100];
$coverUrl = sf_cover_url($album);
$trackCount = $album ? count($album['tracks'] ?? []) : 0;
$isOnlineAlbum = $album && (($album['album_type'] ?? '') === 'online_links');
?><!DOCTYPE html>
<html lang="es" data-theme="<?= lab_h($sfTheme) ?>" data-theme-source="server">
<head>
  <script src="assets/import-progress-dismiss.js?v=results1"></script><script src="assets/youtube-review.js?v=1"></script><script src="assets/album-tracks-live.js?v=1"></script>
  <meta charset="utf-8"><meta name="sf-csrf" content="<?= lab_h(lab_auth_csrf_token()) ?>"><meta name="sf-client-memory-mib" content="<?= (int)$sfMemEffective ?>"><meta name="sf-webcodecs-enabled" content="<?= !empty($cfg['webcodecs_enabled'])?'1':'0' ?>"><meta name="sf-client-memory-default-mib" content="<?= (int) ($cfg['client_memory_mib'] ?? 0) ?>">
<link rel="stylesheet" href="assets/track-menu.css?v=5"><script src="assets/track-menu.js?v=20261113-properties" defer></script>
<script src="assets/track-reuse.js?v=20261116-console1" defer></script><link rel="stylesheet" href="assets/track-reuse.css?v=youtube-review1">
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <meta name="application-name" content="HLS Music Lab">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $album ? lab_h($album['title']) : 'Nuevo álbum' ?> · Lab</title>
  <script>try{document.documentElement.dataset.theme=<?= json_encode($sfTheme) ?>}catch(e){document.documentElement.dataset.theme=<?= json_encode($sfTheme) ?>}</script>
<link rel="stylesheet" href="assets/lab.css?v=theme-sweep1">
<link rel="stylesheet" href="assets/player.css?v=mini-contrast1"><link rel="stylesheet" href="assets/album-track-artwork.css?v=1"><script src="assets/album-track-artwork.js?v=2" defer></script><link rel="stylesheet" href="assets/album-track-order.css?v=1"><script src="assets/album-track-order.js?v=1" defer></script>

  <style>
    /* Alcance exclusivo: editor de álbum */
    body:has(.lab-tracks-card) .lab-main { max-width: 1600px; width: calc(100% - 32px); margin-inline: auto; box-sizing: border-box; }
    .lab-tracks-card { width: 100%; min-width: 0; box-sizing: border-box; }
    .lab-tracks-scroll { width: 100%; overflow-x: auto; }
    .lab-tracks-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: .9rem; }
    .lab-tracks-table th { text-align: left; color: var(--muted); padding: 12px 10px; border-bottom: 1px solid var(--border); }
    .lab-tracks-table td { padding: 10px; border-bottom: 1px solid var(--border); vertical-align: middle; min-width: 0; }
    .lab-tracks-table th:nth-child(1) { width: 4%; }
    .lab-tracks-table th:nth-child(2) { width: 26%; }
    .lab-tracks-table th:nth-child(3) { width: 13%; }
    .lab-tracks-table th:nth-child(4) { width: 34%; }
    .lab-tracks-table th:nth-child(5) { width: 23%; }
    .lab-track-name strong { overflow-wrap: anywhere; }
    .lab-track-quality small { display: block; color: var(--muted); margin-top: 3px; }
    #ytInspectStatus.yt-validating { color: var(--accent); font-weight: 600; }
    .lab-track-path code { display: block; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; max-width: 100%; font-size: .76rem; }
    .lab-track-buttons { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
    .lab-track-buttons form { display: contents; }
    .lab-icon-btn { display: inline-flex; flex: 1 1 0; min-width: 0; min-height: 44px; padding: 5px 4px; flex-direction: column; align-items: center; justify-content: center; gap: 2px; border: 0; border-radius: 6px; color: white; text-decoration: none; cursor: pointer; font: inherit; font-size: .64rem; font-weight: 700; line-height: 1.1; }
    .lab-icon-btn svg { width: 19px; height: 19px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
    .lab-icon-btn span { overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
    .lab-icon-btn:hover { filter: brightness(1.12); }
    .lab-icon-btn:focus-visible { outline: 3px solid var(--text); outline-offset: 2px; }
    .lab-icon-btn:disabled { opacity: .55; cursor: wait; }
    .lab-btn-play { background: var(--primary); flex: 0 0 36px; }
    .lab-btn-rebuild { background: var(--accent); }
    .lab-btn-edit { background: var(--primary-dim); }
    .lab-btn-delete { background: var(--danger); }
    /* Paneles de edición: dos columnas en escritorio y una en pantallas estrechas. */
    .lab-album-layout { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; align-items: stretch; margin-bottom: 12px; }
    .lab-album-layout > .card { margin: 0; min-width: 0; box-sizing: border-box; }
    .lab-album-layout #albumMetaCard { grid-column: 1; grid-row: 1; border: 1px solid var(--primary); }
    .lab-album-layout #albumDiscoveryCard { grid-column: 2; grid-row: 2; }
    .lab-album-layout #youtubeImportCard { grid-column: 1; grid-row: 2; min-width: 0; min-height: 500px; }
    .lab-album-layout #addTracksForm { grid-column: 2; grid-row: 1; min-height: 500px; }
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
    /* Textareas ocupan todo el ancho útil, con padding moderado y scroll si crece el contenido. */
    .lab-album-layout textarea { display: block; width: 100%; max-width: 100%; min-width: 0; box-sizing: border-box; padding: 10px 12px; line-height: 1.45; border-radius: 8px; resize: vertical; overflow: auto; }
    #ytUrls { min-height: 112px; max-height: 260px; }
    #ytCookiesText { min-height: 88px; max-height: 220px; }
    @media (max-width: 950px) {
      .lab-tracks-table, .lab-tracks-table tbody, .lab-tracks-table tr, .lab-tracks-table td { display: block; width: 100%; box-sizing: border-box; }
      .lab-tracks-table thead { display: none; }
      .lab-tracks-table tr { border: 1px solid var(--border); border-radius: 9px; margin: 0 0 12px; padding: 8px; }
      .lab-tracks-table td { border: 0; padding: 5px 7px; }
      .lab-track-number { color: var(--muted); }
      .lab-track-path code { max-width: 100%; }
      .lab-track-buttons { max-width: 420px; }
    }
    @media (max-width: 760px) {
      .lab-album-layout { grid-template-columns: minmax(0, 1fr); }
      .lab-album-layout #albumMetaCard,
      .lab-album-layout #albumDiscoveryCard,
      .lab-album-layout #youtubeImportCard,
      .lab-album-layout #addTracksForm,
      .lab-album-layout .album-cover-card { grid-column: 1; grid-row: auto; }
      .lab-album-layout #albumMetaCard { order: 1; }
      .lab-album-layout #addTracksForm { order: 2; min-height: 0; }
      .lab-album-layout #youtubeImportCard { order: 3; min-height: 0; }
      .lab-album-layout #albumDiscoveryCard { order: 4; }
      .lab-album-layout .album-cover-card { order: 5; }
      .album-cover-card .cover-editor-layout { grid-template-columns: minmax(0, 1fr); gap: 18px; }
      .album-cover-card .cover-preview { width: min(180px, 100%); }
      .album-cover-card .cover-controls { max-width: none; }
    }
  .progress-panel>.progress-ring-wrap{width:180px;height:auto}.progress-panel .dual-progress-label{position:static;transform:none;inset:auto;display:flex;flex-direction:row;justify-content:center;gap:18px;margin-top:4px;width:180px}.progress-panel .dual-progress-label small{font-size:11px}.progress-panel .dual-progress-label b{font-size:15px}@media(min-width:800px){.progress-panel>.progress-ring-wrap{margin-left:calc((100% - 350px - 180px)/2)}}
</style>
<link rel="stylesheet" href="assets/track-editor.css?v=theme-sweep1">
<script src="assets/membership-picker.js?v=1"></script><link rel="stylesheet" href="assets/membership-picker.css?v=theme-sweep1">
<meta name="sf-player-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES, 'UTF-8') ?>"><link rel="stylesheet" href="assets/container-memory.css?v=storage1"><style>
.track-progress-ring{stroke:var(--accent)!important}.dual-progress-label{display:flex;flex-direction:column;gap:3px;font-size:14px!important;line-height:1.2}.dual-progress-label span{display:flex;flex-direction:column}.dual-progress-label small{font-size:9px;font-weight:400}.dual-progress-label span:first-child{color:var(--primary)}.dual-progress-label span:last-child{color:var(--accent)}
</style><style>
@media(min-width:1000px){body .lab-main,body:has(.lab-tracks-card) .lab-main{width:calc((100% - 32px)*.924);max-width:1478.4px;margin-inline:auto}}
#createForm{margin-top:0;padding-top:0}#createForm>h2:first-child{margin-top:0;padding-top:0}#createForm:before{display:none}
.lab-tracks-table th,.lab-tracks-table td{padding:7px 8px;vertical-align:middle}.lab-tracks-table .lab-track-number{white-space:nowrap;overflow-wrap:normal}.lab-track-buttons{display:flex;align-items:center;justify-content:flex-end;gap:5px;flex-wrap:wrap}.lab-tracks-table .lab-track-path{display:none}.lab-tracks-table th:nth-child(4){display:none}.lab-tracks-table th:nth-child(1){width:5%}.lab-tracks-table th:nth-child(2){width:43%}.lab-tracks-table th:nth-child(3){width:17%}.lab-tracks-table th:nth-child(5){width:35%}.lab-tracks-table .lab-icon-btn{padding:5px;min-height:30px}.lab-tracks-table .lab-icon-btn span{display:none}
</style><style>
.progress-track-name{font-weight:600;margin:10px auto 5px;max-width:100%;overflow-wrap:anywhere;line-height:1.4}#progressDetail{margin-top:5px;line-height:1.4;overflow-wrap:anywhere}
@media(min-width:800px){.progress-panel>.progress-ring-wrap,.progress-panel>h2,.progress-panel>#progressTrackName,.progress-panel>#progressDetail{max-width:calc(100% - 350px);margin-left:0;margin-right:350px;text-align:center}.progress-panel>.progress-ring-wrap{margin-left:calc((100% - 350px - 120px)/2);margin-right:0}.progress-panel>#progressTrackName{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.progress-panel>.progress-log{clear:both}}
.progress-panel{position:relative}.progress-minimize{position:absolute;top:10px;right:10px;z-index:3;padding:4px 10px;min-height:0;line-height:1.1}.progress-panel.progress-panel-mini{position:fixed!important;inset:auto 20px 20px auto!important;width:150px!important;min-width:0!important;height:150px!important;min-height:0!important;max-height:none!important;margin:0!important;padding:6px!important;border-radius:18px!important;overflow:hidden!important;z-index:2147483000;box-shadow:0 6px 24px #0007;text-align:center}.progress-panel.progress-panel-mini> :not(.progress-ring-wrap):not(.progress-restore){display:none!important}.progress-panel.progress-panel-mini>.progress-ring-wrap{width:138px!important;height:138px!important;margin:0!important;display:block}.progress-panel.progress-panel-mini>.progress-ring-wrap svg{width:138px!important;height:138px!important}.progress-panel.progress-panel-mini .progress-ring-label{position:absolute!important;inset:0!important;display:grid!important;place-items:center!important;margin:0!important;width:auto!important;font-size:20px!important;line-height:1.1!important}.progress-panel.progress-panel-mini .progress-ring-label span{display:none!important}.progress-panel.progress-panel-mini .progress-ring-label span:first-child{display:flex!important;flex-direction:column!important;align-items:center!important;gap:0!important}.progress-panel.progress-panel-mini .progress-ring-label small{display:none!important}.progress-panel.progress-panel-mini .progress-ring-label b{font-size:20px!important}.progress-panel.progress-panel-mini .progress-restore{display:block;position:absolute;inset:0;width:100%;height:100%;padding:0;border:0;background:transparent;cursor:pointer;border-radius:inherit}.progress-panel.progress-panel-mini .progress-restore:focus-visible{outline:2px solid var(--primary);outline-offset:-3px}@media(max-width:500px){.progress-panel.progress-panel-mini{right:12px!important;bottom:12px!important}}
</style><script src="assets/artwork-backup.js?v=20261116-console1" defer></script><script src="assets/album-back.js?v=1" defer></script><script src="assets/client-processing.js?v=20261114-worker-controls1" defer></script><link rel="stylesheet" href="assets/album-discovery.css?v=mornye-prepare1"><link rel="stylesheet" href="assets/loading-skeletons.css?v=1"><link rel="stylesheet" href="assets/album-tracks-mobile.css?v=1">
<script src="assets/audio-download.js?v=1" defer></script><link rel="stylesheet" href="assets/audio-download.css?v=1">
<meta name="sf-session-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><script src="assets/session-sync.js?v=auth-poll3" defer></script>
<link rel="stylesheet" href="assets/reused-track-rows.css?v=2"><meta name="sf-save-result" content="<?= $err!==''?'error':($ok!==''?'success':'') ?>"><link rel="stylesheet" href="assets/save-feedback.css?v=2"><script src="assets/save-feedback.js?v=2"></script><script defer src=assets/sf-language.js></script></head>
<body<?= ($_GET['embed']??'')==='1' ? ' class="config-embed"' : '' ?>>
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
      <a class="btn" href="index.php?view=library" data-album-back>← Regresar</a>
    </div>
  </header>
  <main class="lab-main">
    <?php if ($err): ?><div class="banner err"><?= lab_h($err) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="banner ok"><?= lab_h($ok) ?></div><?php endif; ?>

    <div id="progressPanel" class="card progress-panel" hidden>
      <h2>Convirtiendo a HLS…</h2><details class="progress-results"><summary>Resultados del proceso</summary><ul class="progress-log" id="progressLog" tabindex="0" aria-label="Resultados de las pistas procesadas"></ul></details><button type="button" class="btn progress-minimize" id="progressMinimize" title="Minimizar el progreso" aria-label="Minimizar progreso">−</button><aside id="containerMemory" class="container-memory" aria-label="Uso de memoria de los contenedores"><div class="memory-row" data-container="www"><strong>www</strong><output>Esperando…</output><progress max="100" value="0" aria-label="Memoria de www"></progress><small>—</small></div><div class="memory-row storage-row"><strong>Storage</strong><output id="storagePercent">Esperando…</output><progress id="storageBar" max="100" value="0" aria-label="Espacio de almacenamiento usado"></progress><small id="storageDetail">—</small></div><div class="memory-note">Memoria usada / límite · 0–100%</div></aside>
      <div class="progress-ring-wrap">
        <svg class="progress-ring" width="180" height="180" viewBox="0 0 120 120">
          <circle class="progress-ring-bg" cx="60" cy="60" r="52" />
          <circle class="progress-ring-fg" id="progressRingFg" cx="60" cy="60" r="52" /><circle class="progress-ring-bg" cx="60" cy="60" r="39"/><circle class="progress-ring-fg track-progress-ring" id="trackProgressRing" cx="60" cy="60" r="39"/>
        <defs><clipPath id="albumCpuClip"><circle cx="60" cy="60" r="28"/></clipPath></defs><g transform="rotate(90 60 60)" data-cpu-meter><circle cx="60" cy="60" r="28" fill="#29434e" style="fill:var(--surface-raised,#29434e);stroke:var(--border,#29434e);stroke-width:1"/><rect data-cpu-fill data-cpu-size="56" data-cpu-bottom="88" x="32" y="88" width="56" height="0" fill="#22c55e" fill-opacity=".5" clip-path="url(#albumCpuClip)" style="transition:y .5s,height .5s,fill .5s"/><text x="60" y="55" text-anchor="middle" fill="currentColor" font-size="7" font-weight="600">CPU www</text><text data-cpu-label x="60" y="68" text-anchor="middle" fill="currentColor" font-size="12" font-weight="700">—</text></g></svg>
        <div class="progress-ring-label dual-progress-label"><span><small id="batchProgressLabel">Lote</small> <b id="progressPct">0%</b></span><span><small>Canción</small> <b id="trackProgressPct">0%</b></span></div>
      </div>
      <p id="progressTrackName" class="progress-track-name" hidden></p><p class="muted" id="progressDetail">Preparando…</p>
      
      <button type="button" class="progress-restore" id="progressRestore" aria-label="Mostrar progreso completo" title="Mostrar progreso completo" hidden></button>
    </div>

    <?php if (!$album): ?>
    <form class="card" id="createForm">
      <h2>Crear álbum y añadir pistas</h2>
      <p class="muted">Indica un título y elige cómo añadir pistas: archivos, YouTube o crear el álbum vacío para incorporar canciones existentes.</p>
      <label>Título del álbum *</label>
      <input type="text" id="newTitle" name="title" required placeholder="Ej. Porfiado 2012" value="">
      <label>Artista (opcional)</label>
      <input type="text" id="newArtist" name="artist" placeholder="Artista" value="">
      <label>Archivos de audio (varios)</label>
      <input type="file" id="newFiles" accept="<?= lab_h($accept) ?>" multiple>
      <p class="muted">Formatos: <?= lab_h(implode(', ', lab_allowed_audio_extensions($cfg))) ?>. Máx. <?= (int)$cfg['max_upload_mib'] ?> MiB c/u.</p>
      <div class="header-actions">
        <button class="btn btn-lg" type="button" id="discoverAlbumBtn">Buscar un álbum y agregarlo</button>
        <button class="btn primary btn-lg" type="submit">Crear álbum y convertir archivos</button>
        <button class="btn btn-lg" type="button" id="createYoutubeAlbumBtn"><img src="assets/youtube-brand.svg" width="22" height="18" alt="" style="vertical-align:middle;margin-right:7px">Crear álbum y añadir desde YouTube</button>
<button class="btn btn-lg" type="button" id="createMornyeAlbumBtn"><img src="assets/mornye-brand.svg" width="22" height="22" alt="" style="vertical-align:middle;margin-right:7px">Crear álbum y añadir desde Mornye</button>
        <button class="btn btn-lg" type="button" id="createEmptyAlbumBtn" title="Crea el álbum sin subir ni convertir archivos; después puedes asignarle pistas existentes">Solo crear álbum</button>
      </div>
    </form>
    <?php else: ?>

    <?php if(isset($_GET['empty'])): ?><div class="card"><p>El álbum está disponible para incorporar pistas existentes.</p><a class="btn" href="index.php?view=library#loose-tracks">Ir a pistas sin asignar</a><p class="muted">Selecciona este álbum como destino en la pista que quieras mover, o arrástrala sobre su ficha en Biblioteca.</p></div><?php endif; ?>

    <?php if ($trackCount > 0 || !$isOnlineAlbum): ?>
    <div class="card play-cta">
      <?php if ($trackCount > 0): ?>
      <p><strong><?= $trackCount ?></strong> pista(s) listas en este álbum.</p>
      <a class="btn primary btn-lg" href="player.php?album=<?= lab_h(urlencode($album['id'])) ?>">▶ Reproducir álbum</a>
      <button class="btn" type="button" id="showAlbumInLibrary" data-album="<?= lab_h($album['id']) ?>" title="Cierra la gestión y resalta la tarjeta del álbum en la biblioteca">Mostrar en la biblioteca</button>
      <?php endif; ?>
      <?php if (!$isOnlineAlbum): ?><button class="btn" type="button" id="mornyeAlbumProgress" title="Abre el progreso de descarga, conversión e incorporación del lote de Mornye">Ver progreso</button><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="lab-album-layout">
    <form class="card highlight-card" id="albumMetaCard" method="post"><input type="hidden" name="csrf_token" value="<?= lab_h(lab_auth_csrf_token()) ?>">
      <input type="hidden" name="action" value="save_meta">
      <h2>Datos del álbum</h2>
      <label>Título</label>
      <input type="text" name="title" required value="<?= lab_h($album['title'] ?? '') ?>">
      <label>Artista</label>
      <input type="text" name="artist" value="<?= lab_h($album['artist'] ?? '') ?>">
      <button class="btn primary" type="submit">Guardar datos</button>
    </form>

    <?php if (!$isOnlineAlbum): ?>
    <section class="card" id="albumDiscoveryCard" aria-label="Buscar álbum e incorporar pistas">
      <div data-album-discovery-inline></div>
      <noscript>Activa JavaScript para buscar álbumes e incorporar sus pistas.</noscript>
    </section>
    <?php endif; ?>

    <?php if (!$isOnlineAlbum): ?>
    <form class="card highlight-card" id="addTracksForm">
      <h2>Añadir pistas (archivos source)</h2>
      <p class="muted">Se guardan en <code>hls_out/<?= lab_h($album['id']) ?>_…/</code></p>
      <input type="file" id="addFiles" accept="<?= lab_h($accept) ?>" multiple required>
      <button class="btn primary btn-lg" type="submit">Subir y convertir a HLS</button>
    </form>

    <section class="card highlight-card" id="youtubeImportCard">
      <h2>Importar desde YouTube</h2>
      <p class="muted">Pega hasta 100 enlaces de videos individuales, uno por línea. Primero se inspeccionan sin descargar; después podrás excluir videos y editar el nombre de salida.</p>
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
        <button class="btn primary" type="button" id="ytInspectBtn">Verificar enlaces</button>
      </div>
      <p class="muted">Elige el formato de audio original de cada melodía. Se descarga sin recodificar; la conversión HLS se realiza posteriormente.</p>
      <div id="ytInspectStatus" class="muted" role="status" aria-live="polite"></div>
      <div data-local-youtube-control><label title="Solo escritorio. Si cierras la pestaña se interrumpe la conversión local y no se publica la pista. La fuente se conserva 24 horas."><input type="checkbox" id="ytLocalProcessing" checked> Convertir YouTube en este equipo</label><p class="muted">Descarga en el servidor → conversión en tu equipo → publicación en el servidor. Mantén esta pestaña abierta hasta terminar.</p></div><div id="ytResults" class="job-list" style="margin-top:1rem"></div>
      <button class="btn primary btn-lg" type="button" id="ytImportBtn" disabled>Descargar e importar seleccionadas</button>
      <div id="ytImportStatus" class="muted" role="status" aria-live="polite"></div>
    </section>
    <?php else: ?>
    <section class="card"><h2>Modo de álbum virtual</h2><p class="muted">Este álbum conserva enlaces externos y no crea archivos HLS. Para convertir canciones a HLS, crea un álbum local independiente.</p></section>
    <?php endif; ?>

    <form class="card album-cover-card" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= lab_h(lab_auth_csrf_token()) ?>">
      <input type="hidden" name="action" value="cover">
      <h2>Portada y encuadre</h2>
      <div class="cover-editor-layout">
        <div class="cover-image-column">
          <div class="cover-preview" id="coverPreview" style="<?php
            if ($coverUrl) {
              echo 'background-image:url(&quot;' . lab_h($coverUrl) . '&quot;);';
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
      (()=>{
        const input=document.getElementById('coverImageFile');
        if(!input)return;
        input.addEventListener('change',()=>{
          const file=input.files&&input.files[0];
          const preview=document.getElementById('coverPreview');
          if(!file||!preview||!/^image\//i.test(file.type||''))return;
          if(preview.dataset.previewUrl)URL.revokeObjectURL(preview.dataset.previewUrl);
          const url=URL.createObjectURL(file);
          preview.dataset.previewUrl=url;
          preview.style.backgroundImage='url("'+url.replace(/"/g,'%22')+'")';
          preview.querySelector('span')?.remove();
          updCover();
        });
        window.addEventListener('pagehide',()=>{
          const preview=document.getElementById('coverPreview');
          if(preview&&preview.dataset.previewUrl)URL.revokeObjectURL(preview.dataset.previewUrl);
        });
      })();
    </script>
    </div><!-- /.lab-album-layout -->

    <section class="card lab-tracks-card">
      <div class="album-tracks-heading"><h2>Pistas (<?= $trackCount ?>)</h2><label class="album-artwork-toggle"><input type="checkbox" id="showTrackArtwork" aria-controls="albumTracksTable"> Mostrar carátula</label><label class="album-artwork-toggle"><input type="checkbox" id="reorderAlbumTracks" autocomplete="off" aria-controls="albumTracksTable"> Reordenar</label><span id="albumOrderStatus" class="muted" role="status" aria-live="polite"></span></div>
      <?php if ($trackCount === 0): ?>
        <p class="muted">Aún no hay pistas. Usa <strong>Añadir pistas</strong>.</p>
      <?php else: ?>
      <div class="lab-tracks-scroll">
        <table class="lab-tracks-table" id="albumTracksTable">
          <thead><tr><th scope="col">#</th><th scope="col">Pista</th><th scope="col"><?= $isOnlineAlbum ? 'Duración' : 'Calidad' ?></th><th scope="col"><?= $isOnlineAlbum ? 'Enlace de origen' : 'Ruta HLS' ?></th><th scope="col">Acciones</th></tr></thead>
          <tbody>
          <?php foreach ($album['tracks'] as $i => $t): ?>
          <tr class="<?= !empty($t['_ref_source'])?'sf-reused-track-row':'' ?>" data-track-id="<?= lab_h((string)($t['id']??'')) ?>">
            <td class="lab-track-number" data-label="#"><?= $i + 1 ?></td>
            <td class="lab-track-name" data-label="Pista"><div class="album-track-identity" data-artwork-track="<?=lab_h((string)($t['id']??''))?>"><?php
$trackArtwork=trim((string)($t['artwork']??''));
if($trackArtwork!=='' && !preg_match('~^https://~i',$trackArtwork))$trackArtwork='';
?><span class="album-track-artwork" aria-hidden="true"><span></span><?php if($trackArtwork!==''): ?><img data-artwork-src="<?=lab_h($trackArtwork)?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"><?php endif; ?></span><strong><?=lab_h($t['name']??'')?></strong></div></td>
            <td class="lab-track-quality" data-label="<?= $isOnlineAlbum ? 'Duración' : 'Calidad' ?>">
              <?php if ($isOnlineAlbum): ?><span><?= (int)($t['duration'] ?? 0) > 0 ? gmdate(((int)$t['duration'] >= 3600 ? 'H:i:s' : 'i:s'), (int)$t['duration']) : '—' ?></span>
              <?php else: ?><span>~<?= (int)($t['source_kbps'] ?? 0) ?> kbps</span><small><?= lab_h(implode(', ', $t['renditions'] ?? [])) ?></small><?php endif; ?>
            </td>
            <td class="lab-track-path" data-label="<?= $isOnlineAlbum ? 'Enlace de origen' : 'Ruta HLS' ?>"><code title="<?= lab_h($isOnlineAlbum ? ($t['source_url'] ?? '') : ($t['hls'] ?? '')) ?>"><?= lab_h($isOnlineAlbum ? ($t['source_url'] ?? '') : ($t['hls'] ?? '')) ?></code></td>
            <td class="lab-track-actions" data-label="Acciones">
              <div class="lab-track-buttons"<?= (!$isOnlineAlbum && !empty($t['id'])) ? ' data-sf-track="'.lab_h((string)$t['id']).'"' : '' ?>><details class="sf-track-options"><summary aria-label="Opciones de pista">⋮</summary><div class="sf-track-options-panel"><?php if(!$isOnlineAlbum): ?><button type="button" class="btn" data-replace-track="<?=lab_h($t['id'])?>" data-replace-title="<?=lab_h((string)($t['name']??$t['title']??''))?>" data-replace-album="<?=lab_h($t['_ref_source']??$album['id'])?>">Reemplazar</button><?php endif; ?><button type="button" data-track-properties="<?= lab_h($t['id']) ?>">Propiedades</button><button type="button" class="btn" data-reuse-source="<?= lab_h($t['_ref_source']??$album['id']) ?>" data-reuse-track="<?= lab_h($t['id']) ?>">Añadir a otra colección</button><?php if(($album['id']??'')!=='fffffffffffffffe'): ?><button type="button" class="btn" data-detach-track="<?= lab_h($t['id']) ?>" data-detach-album="<?= lab_h($album['id']) ?>">Quitar de esta colección</button><?php endif; ?></div></details>
                <a class="lab-icon-btn lab-btn-play" title="Reproducir pista" aria-label="Reproducir <?= lab_h($t['name'] ?? '') ?>" href="player.php?album=<?= lab_h(urlencode($album['id'])) ?>&i=<?= $i ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 5 12 7-12 7z" fill="currentColor" stroke="none"/></svg></a>
                <?php if (!$isOnlineAlbum && empty($t['_ref_source'])): ?>
                <form method="post" onsubmit="return confirm('¿Reconstruir source?');"><input type="hidden" name="csrf_token" value="<?= lab_h(lab_auth_csrf_token()) ?>">
                  <input type="hidden" name="action" value="reconstruct">
                  <input type="hidden" name="job_id" value="<?= lab_h($t['job_id'] ?? '') ?>">
                  <button class="lab-icon-btn lab-btn-rebuild" type="submit" title="Reconstruir source" aria-label="Reconstruir source"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12V9a3 3 0 0 1 3-3h13m-3-3 3 3-3 3M20 12v3a3 3 0 0 1-3 3H4m3 3-3-3 3-3M10 10l-2 2 2 2m4-4 2 2-2 2"/></svg><span>Reconstruir</span></button>
                </form>
                <button class="lab-icon-btn lab-btn-edit" data-edit-track="<?= lab_h((string)($t['id']??'')) ?>" type="button" title="Editar metadatos, portada y audio" data-track-id="<?= lab_h((string)($t['id'] ?? '')) ?>" data-track-name="<?= lab_h((string)($t['name'] ?? '')) ?>" data-track-title="<?= lab_h((string)($t['title'] ?? '')) ?>" data-track-artist="<?= lab_h((string)($t['artist'] ?? '')) ?>" data-track-album="<?= lab_h((string)($t['album'] ?? '')) ?>" data-track-genre="<?= lab_h((string)($t['genre'] ?? '')) ?>" data-track-year="<?= lab_h((string)($t['year'] ?? '')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><span>Editar</span></button>
                <button class="lab-icon-btn lab-btn-delete delete-track-btn" type="button" title="Eliminar pista" data-track-id="<?= lab_h((string)($t['id'] ?? '')) ?>" data-track-name="<?= lab_h((string)($t['name'] ?? 'esta pista')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11l4 4m0-4-4 4"/></svg><span>Eliminar</span></button>
                <?php else: ?><?php if(!empty($t['_ref_source']) && preg_match('/^[a-f0-9]{16}$/D',(string)$t['_ref_source'])): ?><a class="btn album-track-origin" href="<?=lab_h($t['_ref_source']==='fffffffffffffffe'?'index.php?view=library#loose-tracks':'album_edit.php?id='.rawurlencode((string)$t['_ref_source']))?>" title="Abrir la colección de origen para gestionar esta pista">Ir a colección de origen ↗</a><?php endif; ?><?php if(empty($t['_ref_source'])): ?><span class="muted">Enlace externo</span><?php endif; ?><?php endif; ?>
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
    const progressMinimize=document.getElementById('progressMinimize'),progressRestore=document.getElementById('progressRestore');
    function minimizeProgressPanel(){if(panel.hidden)return;panel.classList.add('progress-panel-mini');if(progressRestore){progressRestore.hidden=false;progressRestore.focus({preventScroll:true});}}
    function restoreProgressPanel(){panel.classList.remove('progress-panel-mini');if(progressRestore)progressRestore.hidden=true;}
    if(progressMinimize)progressMinimize.onclick=minimizeProgressPanel;
    if(progressRestore)progressRestore.onclick=restoreProgressPanel;
    const fg = document.getElementById('progressRingFg');
    const pctEl = document.getElementById('progressPct');
    const detail = document.getElementById('progressDetail');
    const trackCaption=document.getElementById('progressTrackName');
    let progressTrackName='';
    function setProgressTrackName(name){progressTrackName=String(name||'');trackCaption.textContent=progressTrackName;trackCaption.title=progressTrackName;trackCaption.hidden=!progressTrackName;}

    const logEl = document.getElementById('progressLog');
    if (fg) {
      fg.style.strokeDasharray = String(RING_C);
      fg.style.strokeDashoffset = String(RING_C);
    }
    const trackRing=document.getElementById('trackProgressRing');
    const trackPct=document.getElementById('trackProgressPct');
    const TRACK_C=2*Math.PI*39;
    function setTrackProgress(value){const n=Math.max(0,Math.min(100,Number(value)||0));trackRing.style.strokeDasharray=String(TRACK_C);trackRing.style.strokeDashoffset=String(TRACK_C*(1-n/100));trackPct.textContent=Math.round(n)+'%';}
    setTrackProgress(0);
    function setProgress(pct, text, source='foreground') {
      // Never mix active-batch progress with the per-job recovery percentage.
      if(source==='recovery' && foregroundImport) return;
      document.getElementById('batchProgressLabel').textContent=source==='recovery'?'Cola activa':'Lote';
      pct = Math.max(0, Math.min(100, pct));
      if (pctEl) pctEl.textContent = Math.round(pct) + '%';
      if (fg) fg.style.strokeDashoffset = String(RING_C * (1 - pct / 100));
      if (detail && text) {
        let status=String(text);
        if(progressTrackName)status=status.replace(progressTrackName,'').replace(/[\s·:—-]+$/u,'');
        status=status.replace('Convirtiendo y segmentando HLS','Convirtiendo').replace('Publicando en biblioteca','Publicando');
        detail.textContent=status;detail.title=String(text);
      }
    }
    function logLine(msg, ok) {
      if(ok===false)panel.dataset.progressError='1';
      if (!logEl) return;
      const li = document.createElement('li');
      li.textContent = msg;
      if (ok === true) li.className = 'ok';
      if (ok === false) li.className = 'err';
      logEl.appendChild(li);dismissProgress.record(ok);
    }

    // La cola vive en SQLite: al recargar, recuperamos los trabajos del álbum.
    // El indicador no depende de variables JS de la pestaña anterior.
    const activeAlbumId = <?= json_encode($album['id'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
    let foregroundImport = false;
    const dismissProgress=window.sfImportProgressDismiss.attach(panel,{isBusy:()=>foregroundImport||hadActiveJobs||window.sfClientProcessing?.busy?.()});
    let foregroundEpoch=0;
    const recoveredJobs=new Map(),recoveredResults=new Set();
    let recoveringJobs = false;
    let hadActiveJobs = false;
    let refreshScheduled = false;
    const scheduleAlbumRefresh = async () => {
      if(refreshScheduled)return;refreshScheduled=true;const refreshEpoch=foregroundEpoch;
      try{await window.sfRefreshAlbumTracks({appendOnly:true});if(refreshEpoch!==foregroundEpoch)return;hadActiveJobs=false;setProgress(100,'Procesamiento finalizado. Listado actualizado sin recargar.');}
      catch(error){if(refreshEpoch!==foregroundEpoch)return;logLine('No se pudo actualizar el listado: '+error.message,false);}
      finally{refreshScheduled=false;}
    };
    function renderRecoveredAlbumLinks(jobs) {
      let links=panel.querySelector('.recovered-album-links');
      if(activeAlbumId){links?.remove();return;}
      const albums=new Map();
      for(const job of jobs){
        const id=String(job.album_id||'');
        if(/^[a-f0-9]{16}$/.test(id))albums.set(id,id==='fffffffffffffffe'?'Pistas sin asignar a colección':String(job.album_title||'Álbum'));
      }
      if(!albums.size){links?.remove();return;}
      const signature=JSON.stringify([...albums]);
      if(links?.dataset.signature===signature)return;
      const selected=links?.querySelector('select')?.value;
      if(!links){links=document.createElement('p');links.className='recovered-album-links';panel.appendChild(links);}
      links.replaceChildren();links.dataset.signature=signature;
      const a=document.createElement('a');a.className='btn';
      let id=albums.has(selected)?selected:albums.keys().next().value;
      if(albums.size>1){
        const select=document.createElement('select');select.setAttribute('aria-label','Álbum con importaciones pendientes');
        for(const [value,title] of albums){const option=document.createElement('option');option.value=value;option.textContent=title;select.appendChild(option);}
        select.value=id;select.style.maxWidth='100%';select.style.marginInlineEnd='8px';
        select.addEventListener('change',()=>{a.href='album_edit.php?id='+encodeURIComponent(select.value);});
        links.appendChild(select);
      }
      a.href='album_edit.php?id='+encodeURIComponent(id);
      a.textContent=albums.size===1?'Ver '+albums.get(id):'Ver álbum en proceso';links.appendChild(a);
    }
    async function syncAlbumJobs() {
      if (foregroundImport || recoveringJobs || document.hidden) return;
      recoveringJobs = true;const epoch=foregroundEpoch;
      try {
        const response = await fetch('api_processing_album_jobs.php?album_id=' + encodeURIComponent(activeAlbumId)+'&tracked_ids='+encodeURIComponent([...recoveredJobs.keys()].slice(0,100).join(',')), {
          credentials: 'same-origin', cache: 'no-store'
        });
        if (!response.ok) return;
        const data = await response.json();
        if(foregroundImport||epoch!==foregroundEpoch)return;
        for(const job of Array.isArray(data.finished)?data.finished:[]){
          if(!recoveredJobs.has(job.id)||recoveredResults.has(job.id))continue;
          logLine((job.state==='failed'?'ERROR: ':'OK: ')+job.label+(job.state==='failed'?' — '+(job.error||'No se pudo completar la pista.'):''),job.state!=='failed');
          recoveredJobs.delete(job.id);recoveredResults.add(job.id);
        }
        const jobs = (Array.isArray(data.jobs) ? data.jobs : []).filter(j => ['upload','youtube','client_hls'].includes(j.operation) && ['queued','running'].includes(j.state));
        for(const job of jobs)if(job.id)recoveredJobs.set(job.id,job.label);
        if (jobs.length) {
          hadActiveJobs = true;
          if (!foregroundImport && panel) {
            panel.classList.add('sf-import-progress-float');
            panel.hidden = !dismissProgress.recover();
            const title = panel.querySelector('h2');
            if (title) title.textContent = 'Importación en curso…';
            renderRecoveredAlbumLinks(jobs);

            const total = jobs.length;
            const average = jobs.reduce((sum, j) => sum + (Number(j.progress) || 0), 0) / total;
            const current = jobs.find(j => j.state === 'running') || jobs[0];
            setProgressTrackName(current.label);setTrackProgress(current.progress);
            const description = current.state === 'queued'
              ? 'En cola: ' + current.label
              : (current.stage || 'Procesando') + ': ' + current.label;
            setProgress(average, `${total} trabajo(s) pendiente(s) · ${description}`, 'recovery');
          }
        } else if (hadActiveJobs && !foregroundImport) {
          hadActiveJobs=false;
          for(const [id,label] of recoveredJobs){logLine('No se pudo confirmar el resultado: '+label,false);recoveredResults.add(id);}recoveredJobs.clear();
          panel?.querySelector('.recovered-album-links')?.remove();
          if(activeAlbumId)await scheduleAlbumRefresh();
          if(foregroundImport||epoch!==foregroundEpoch)return;
          const failed=!!panel.dataset.progressError;
          setProgress(100,failed?'Proceso terminado con errores. Revisa los resultados.':'Proceso finalizado correctamente.');
          const title=panel.querySelector('h2');if(title)title.textContent=failed?'Proceso terminado con errores':'Proceso finalizado';
          dismissProgress.finish({success:!failed});
        }
      } catch (error) {
        // Un error transitorio de red no debe ocultar el indicador ni recargar la página.
      } finally {
        recoveringJobs = false;
      }
    }
    {
      syncAlbumJobs();
      window.setInterval(syncAlbumJobs, 2500);
      document.addEventListener('visibilitychange', () => { if (!document.hidden) syncAlbumJobs(); });
    }

    async function waitForProcessingJob(jobId, onUpdate) {if(panel)panel.dataset.resourceJob=jobId;
      for (;;) {
        await new Promise(resolve => setTimeout(resolve, 2000));
        const response = await fetch('api_processing_status.php?id=' + encodeURIComponent(jobId), {credentials:'same-origin',cache:'no-store'});
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.job) throw new Error(data.error || 'No se pudo consultar el estado de la cola');
        const job = data.job;
        if (onUpdate) {setProgressTrackName(job.label);setTrackProgress(job.progress);onUpdate(job);}
        if (job.state === 'done') return job.result || {};
        if (job.state === 'failed') throw new Error(job.error || 'Falló el procesamiento');
      }
    }

    let filesNotSent = 0;
    window.addEventListener('beforeunload',event=>{
      if(filesNotSent>0||foregroundImport||window.sfClientProcessing?.busy?.()){
        event.preventDefault();
        event.returnValue='La importación está en curso. Si sales ahora, el proceso se interrumpirá y el álbum quedará inconcluso.';
        return event.returnValue;
      }
    });
    // El botón «Regresar» cierra el gestor dentro de la app; también debe avisar.
    document.addEventListener('click',event=>{
      const back=event.target.closest('[data-album-back]');
      if(!back||!foregroundImport)return;
      if(!window.confirm('La importación está en curso. Si sales ahora, el proceso se interrumpirá y el álbum quedará inconcluso.\n\n¿Deseas salir de todos modos?')){event.preventDefault();event.stopImmediatePropagation();}
    },true);
    window.sfAlbumImportInProgress=()=>foregroundImport;
    // Adjunta a esta colección una pista que ya existe en el catálogo.
    async function attachExistingTrack(match, albumId) {
      if (!match || !match.id || !match.album_id) throw new Error('Coincidencia no válida');
      const fd = new FormData();
      fd.set('csrf_token', document.querySelector('meta[name="sf-csrf"]')?.content || '');
      fd.set('action', 'attach');
      fd.set('source', match.album_id);
      fd.set('track_id', match.id);
      fd.set('target', albumId);
      const r = await fetch('api_track_reuse.php', {method:'POST', body:fd, credentials:'same-origin'});
      const j = await r.json().catch(() => ({}));
      if (!r.ok || j.error) throw new Error(j.error || 'No se pudo reutilizar la pista');
      return j;
    }
    async function convertFiles(albumId, fileList) {
      const files = Array.from(fileList || []).filter(Boolean);
      if (!files.length) throw new Error('Selecciona al menos un archivo');
      filesNotSent=files.length;foregroundImport=true;foregroundEpoch++;dismissProgress.start();let conversionCompleted=false;
      try {
      window.sfYouTubeReview.showProgress(panel);
      logEl.innerHTML = '';
      setProgress(0, 'Iniciando…');
      // Barrido completo de coincidencias al inicio del lote: una sola decisión
      // (sustituir / nueva / reutilizar) para todas las pistas.
      let duplicateDecisions = new Map();
      if (window.sfCheckDuplicates) {
        setProgress(0, 'Revisando coincidencias del lote…');
        const entries = await Promise.all(files.map(async (file, i) => ({
          key: String(i),
          name: file.name,
          duration: await window.sfMediaDuration?.(file) || 0
        })));
        duplicateDecisions = await window.sfCheckDuplicates(entries, albumId, {});
      }
      let done = 0;
      for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const decision = duplicateDecisions.get(String(i)) || {mode:'new'};
        if (decision.mode === 'skip') {
          done++;
          logLine('Omitida ' + file.name + ' (coincidencia con una pista existente)', true);
          setProgress((done / files.length) * 100, `Listo ${done}/${files.length}`);
          continue;
        }
        if (decision.mode === 'reuse' && decision.match) {
          try { await attachExistingTrack(decision.match, albumId); }
          catch (error) { logLine('Error: ' + file.name + ' — ' + error.message, false); throw error; }
          done++;
          logLine('Reutilizada «' + decision.match.name + '» en esta colección', true);
          setProgress((done / files.length) * 100, `Listo ${done}/${files.length}`);
          continue;
        }
        const replacement = decision.mode === 'replace' && decision.match ? decision.match : null;
        const uploadAlbum = replacement?.album_id || albumId;
        setProgressTrackName(file.name);setTrackProgress(0);
        setProgress((done / files.length) * 100, `Convirtiendo ${done + 1}/${files.length}: ${file.name}`);
        const seconds=await window.sfMediaDuration?.(file)||0;
        const fd = new FormData();
fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');
        fd.append('album_id', uploadAlbum);
        fd.append('audio', file, file.name);
        const options = replacement ? {replaceTrackId: replacement.id} : {};
        const r = await window.sfClientProcessing.upload(file,uploadAlbum,(percent,text)=>{setTrackProgress(percent);setProgress((done/files.length)*100,text);},options);
        const raw = await r.text();
        let j = {};
        try { j = JSON.parse(raw); } catch { j = { error: raw.slice(0, 200) }; }
        if (!r.ok || j.error) {
          logLine('Error: ' + file.name + ' — ' + (j.error || r.status), false);
          throw new Error(j.error || ('Falló ' + file.name));
        }
        filesNotSent=Math.max(0,filesNotSent-1);
        if (j.queued && j.job_id) {
          j = await waitForProcessingJob(j.job_id, job => setProgress((done / files.length) * 100, job.state === 'queued' ? `En cola (${job.ahead || 0} delante): ${file.name}` : `${job.stage}: ${file.name}`));
        }
        done++;
        logLine((replacement ? 'Sustituida ' : 'OK ') + file.name + (replacement ? ' → ' + (replacement.name || '') : ' → ' + (j.track?.renditions || []).join(', ')), true);
        setProgress((done / files.length) * 100, `Listo ${done}/${files.length}`);
      }
      setTrackProgress(100);setProgress(100, 'Conversión terminada');conversionCompleted=true;
      return done;
      } catch(error){if(!panel.dataset.progressError)logLine('ERROR: '+(error.message||'No se pudo completar la carga.'),false);throw error;} finally {filesNotSent=0;foregroundImport=false;dismissProgress.finish({success:conversionCompleted});}
    }

    async function createAlbumRequest(url,options,empty=false){
      let response=await fetch(url,options);
      if(response.status!==409)return response;
      const data=await response.clone().json().catch(()=>({}));
      if(data.code!=='album_exists'||!data.existing_album)return response;
      const album=data.existing_album;
      if(!window.confirm(empty?`Ya existe «${album.title}» (${album.tracks_count} pistas). ¿Quieres abrir ese álbum?`:`Ya existe «${album.title}» (${album.tracks_count} pistas). ¿Quieres añadir las pistas a esa misma colección?`)){
        return new Response(JSON.stringify({error:'Operación cancelada. Cambia el nombre si deseas una colección diferente.'}),{status:409,headers:{'Content-Type':'application/json'}});
      }
      options.body.set('existing_album_id',album.id);
      return fetch(url,options);
    }
    window.sfCreateAlbumRequest = createAlbumRequest;
    const createForm = document.getElementById('createForm');
    if (createForm) {
      createForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const title = document.getElementById('newTitle').value.trim();
        const artist = document.getElementById('newArtist').value.trim();
        const files = document.getElementById('newFiles').files;
        if (!title) return alert('Indica un título');
        if (!files?.length) return alert('Selecciona archivos, elige YouTube o pulsa «Solo crear álbum».');
        createForm.querySelector('button[type="submit"]').disabled = true;
        try {
          const fd = new FormData();
fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');
          fd.append('title', title);
          fd.append('artist', artist);
          const r = await createAlbumRequest('api_album.php', { method: 'POST', body: fd, credentials: 'same-origin' });
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
fd2.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');
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
          createForm.querySelector('button[type="submit"]').disabled = false;
        }
      });
    }

    const createEmptyAlbumBtn=document.getElementById('createEmptyAlbumBtn');
    if(createEmptyAlbumBtn)createEmptyAlbumBtn.addEventListener('click',async()=>{
      const title=document.getElementById('newTitle')?.value.trim()||'';
      const artist=document.getElementById('newArtist')?.value.trim()||'';
      if(!title){document.getElementById('newTitle')?.reportValidity();return;}
      const buttons=[...createForm.querySelectorAll('button')];buttons.forEach(b=>b.disabled=true);createEmptyAlbumBtn.textContent='Creando álbum…';
      try{
        const body=new FormData();body.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');body.set('title',title);body.set('artist',artist);
        const response=await createAlbumRequest('api_album.php',{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}},true);
        const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'No se pudo crear el álbum');
        const id=result.album_id||result.album?.id;if(!id)throw Error('No se recibió el identificador del álbum');
        location.href='album_edit.php?id='+encodeURIComponent(id)+'&empty=1';
      }catch(error){alert(error.message||'No se pudo crear el álbum');buttons.forEach(b=>b.disabled=false);createEmptyAlbumBtn.textContent='Solo crear álbum';}
    });

    const createYoutubeAlbumBtn = document.getElementById('createYoutubeAlbumBtn');
    if (createYoutubeAlbumBtn) {
      [createYoutubeAlbumBtn,document.getElementById('createMornyeAlbumBtn')].filter(Boolean).forEach(providerButton=>providerButton.addEventListener('click', async () => {
 const source=providerButton.id==='createMornyeAlbumBtn'?'mornye':'youtube';const originalLabel=providerButton.innerHTML;
        const title = document.getElementById('newTitle')?.value.trim() || '';
        const artist = document.getElementById('newArtist')?.value.trim() || '';
        if (!title) { alert('Indica el título del álbum.'); return; }
        providerButton.disabled = true;
        providerButton.textContent = 'Creando álbum…';
        try {
          const fd = new FormData();
fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');
          fd.append('title', title);
          fd.append('artist', artist);
          const response = await createAlbumRequest('api_album.php', {method:'POST', body:fd, credentials:'same-origin', headers:{'Accept':'application/json'}});
          const result = await response.json().catch(() => ({}));
          if (!response.ok || result.error) throw new Error(result.error || 'No se pudo crear el álbum.');
          const newId = result.album_id || result.album?.id;
          if (!newId) throw new Error('La API no devolvió el identificador del álbum.');
          window.location.href = 'album_edit.php?id=' + encodeURIComponent(newId) + '&'+source+'=1';
        } catch (error) {
          alert(error.message || 'No se pudo crear el álbum.');
          providerButton.disabled = false;
          providerButton.innerHTML = originalLabel;
        }
      }));
    }

    document.addEventListener('click', async (event) => {
      const button = event.target.closest('.delete-track-btn');
      if (!button || button.dataset.busy === '1') return;
      const trackId = button.dataset.trackId || '';
      const trackName = button.dataset.trackName || 'esta pista';
      if (!trackId) { alert('No se encontró el identificador de la pista.'); return; }
      if (!window.confirm('¿Eliminar definitivamente la pista "' + trackName + '" de este álbum? Se quitará de la biblioteca y se intentarán borrar sus archivos HLS.')) return;
      button.dataset.busy = '1';
      button.disabled = true;
      const label = button.querySelector('span');
      const oldText = label ? label.textContent : '';
      if (label) label.textContent = 'Eliminando…';
      try {
        const fd = new FormData();
fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');
        fd.append('action', 'delete_track');
        fd.append('album_id', albumId);
        fd.append('track_id', trackId);
        const response = await fetch('api_album_manage.php', {
          method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.success) throw new Error(result.error || 'No se pudo eliminar la pista.');
        if (result.warning) alert(result.warning);
        await window.sfRefreshAlbumTracks({appendOnly:true,removeMissing:true});
      } catch (error) {
        alert(error.message || 'Error al eliminar la pista.');
        button.dataset.busy = '';
        button.disabled = false;
        if (label) label.textContent = oldText;
      }
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
      return Array.from(ytResults.querySelectorAll('.youtube-track-row')).filter(row => row.dataset.verified==='1' && row.querySelector('.yt-include') && row.querySelector('.yt-filename'));
    }
    function ytVideoKey(value) {
      try {
        const url = new URL(value, location.href);
        return url.searchParams.get('v') || url.pathname.split('/').filter(Boolean).pop() || String(value);
      } catch (_) {
        return String(value);
      }
    }
    let ytReview=null,ytInspectControl=null,ytInspectCancelled=false,ytSubmitting=false;
    function openYtReview(){if(!ytReview)ytReview=window.sfYouTubeReview.create({results:ytResults,status:ytInspectStatus,importButton:ytImportBtn,onCancel:()=>{ytInspectCancelled=true;ytInspectControl?.abort();}});ytReview.open();return ytReview;}
    function ytRenderResults(results){
      const previous=new Map([...ytResults.children].map(row=>[row.dataset.key,row]));const wanted=new Set();ytInspected=results;
      for(const item of results){
        const key=ytVideoKey(item.webpage_url||item.url),old=previous.get(key),signature=JSON.stringify(item);wanted.add(key);if(old?.dataset.signature===signature)continue;
        const saved=old?.dataset.verified==='1'?{include:old.querySelector('.yt-include').checked,name:old.querySelector('.yt-filename').value,format:old.querySelector('.yt-source-format').value}:null;
        const row=document.createElement('div');row.className='youtube-track-row';row.dataset.key=key;row.dataset.signature=signature;row.dataset.verified=item.ok?'1':'0';row.dataset.url=item.webpage_url||item.url;row.dataset.title=item.title||item.filename||'Pista';row.dataset.duration=String(item.duration||0);
        const slot=document.createElement('span');slot.className='sf-album-source-slot';const include=document.createElement('input');include.type='checkbox';include.className='yt-include';include.checked=!!item.ok&&(saved?saved.include:true);include.disabled=!item.ok;include.hidden=!!item.pending;include.setAttribute('aria-label','Incluir '+row.dataset.title);
        const skeleton=document.createElement('span');skeleton.className='sf-album-source-skeleton';skeleton.hidden=!item.pending;skeleton.setAttribute('aria-hidden','true');slot.append(include,skeleton);
        const identity=document.createElement('div');identity.className='yt-row-identity';const title=document.createElement('strong');title.textContent=row.dataset.title;title.title=row.dataset.title;const meta=document.createElement('small');meta.className=item.ok?'muted':item.pending?'muted':'err';meta.textContent=item.pending?'Pendiente de verificación…':item.ok?('Duración: '+(item.duration_text||'Desconocida')+(item.uploader?' · '+item.uploader:'')):(item.error||'No se pudo verificar');meta.title=meta.textContent;identity.append(title,meta);
        const qualityLabel=document.createElement('label');qualityLabel.className='yt-quality-label';const quality=document.createElement('select');quality.className='yt-source-format';quality.setAttribute('aria-label','Calidad de origen de '+row.dataset.title);quality.disabled=!item.ok;quality.append(new Option('Auto · mejor audio disponible',''));
        for(const f of (Array.isArray(item.formats)?item.formats:[])){if(!f.format_id)continue;const codec=String(f.codec||'Audio'),label=/^mp4a\.40\.2$/i.test(codec)?'AAC-LC':/^mp4a\.40\.5$/i.test(codec)?'HE-AAC':/^opus/i.test(codec)?'Opus':codec;quality.append(new Option([label,String(f.ext||'').toUpperCase(),f.kbps?f.kbps+' kbps':'',f.sample_rate?Math.round(f.sample_rate/1000)+' kHz':''].filter(Boolean).join(' · '),String(f.format_id)));}if(saved?.format&&[...quality.options].some(o=>o.value===saved.format))quality.value=saved.format;qualityLabel.append(quality);
        const nameLabel=document.createElement('label');nameLabel.className='yt-name-label';const name=document.createElement('input');name.type='text';name.className='yt-filename';name.required=true;name.disabled=!item.ok;name.setAttribute('aria-label','Nombre al importar de '+row.dataset.title);name.value=saved?saved.name:ytSafeBase(item.filename||item.title||'Pista');nameLabel.append(name);
        row.append(slot,identity,qualityLabel,nameLabel);if(item.pending)row.setAttribute('aria-busy','true');if(old)old.replaceWith(row);else ytResults.append(row);
      }
      for(const [key,row] of previous)if(!wanted.has(key))row.remove();
      ytImportBtn.disabled=foregroundImport||ytInspecting||ytSubmitting||!ytCurrentRows().some(row=>row.querySelector('.yt-include').checked);
    }
    let ytImportLabel = ytImportBtn ? ytImportBtn.textContent : '';
    function setYtImportBusy(busy,label){
      if(!ytImportBtn)return;
      if(busy){
        if(!ytImportBtn.dataset.originalLabel)ytImportBtn.dataset.originalLabel=ytImportLabel;
        ytImportBtn.disabled=true;
        ytImportBtn.classList.add('sf-btn-skeleton');
        ytImportBtn.setAttribute('aria-busy','true');
        ytImportBtn.textContent=label||'En proceso de descarga …';
      }else{
        ytImportBtn.classList.remove('sf-btn-skeleton');
        ytImportBtn.removeAttribute('aria-busy');
        ytImportBtn.textContent=ytImportBtn.dataset.originalLabel||ytImportLabel;
      }
    }
    function ytTrackCell(tag,label,className){
      const cell=document.createElement(tag);
      if(className)cell.className=className;
      if(label)cell.dataset.label=label;
      return cell;
    }
    // Inserta una fila real en la tabla de pistas sin esperar a recargar la página.
    function ytAppendAlbumTrack(track){
      if(!track||!track.id)return false;
      const card=document.querySelector('.lab-tracks-card');
      if(!card)return false;
      let scroll=card.querySelector('.lab-tracks-scroll');
      if(!scroll){
        card.querySelector('.lab-tracks-card > p.muted')?.remove();
        scroll=document.createElement('div');scroll.className='lab-tracks-scroll';
        const table=document.createElement('table');table.className='lab-tracks-table';table.id='albumTracksTable';
        const thead=document.createElement('thead');
        thead.innerHTML='<tr><th scope="col">#</th><th scope="col">Pista</th><th scope="col">Calidad</th><th scope="col">Ruta HLS</th><th scope="col">Acciones</th></tr>';
        const tbody=document.createElement('tbody');
        table.append(thead,tbody);scroll.append(table);card.append(scroll);
      }
      const tbody=scroll.querySelector('tbody');
      if(!tbody||tbody.querySelector('[data-track-id="'+track.id+'"]'))return false;
      const index=tbody.querySelectorAll('tr').length+1;
      const tr=document.createElement('tr');tr.dataset.trackId=track.id;

      const num=ytTrackCell('td','#','lab-track-number');num.textContent=String(index);tr.append(num);

      const nameCell=ytTrackCell('td','Pista','lab-track-name');
      const identity=document.createElement('div');identity.className='album-track-identity';
      const art=document.createElement('span');art.className='album-track-artwork';art.setAttribute('aria-hidden','true');
      art.append(document.createElement('span'));
      const strong=document.createElement('strong');strong.textContent=track.name||'';
      identity.append(art,strong);nameCell.append(identity);tr.append(nameCell);

      const qualityCell=ytTrackCell('td','Calidad','lab-track-quality');
      const kbps=document.createElement('span');kbps.textContent='~'+(Number(track.source_kbps)||0)+' kbps';
      const rends=document.createElement('small');rends.textContent=(track.renditions||[]).join(', ');
      qualityCell.append(kbps,rends);tr.append(qualityCell);

      const pathCell=ytTrackCell('td','Ruta HLS','lab-track-path');
      const code=document.createElement('code');code.textContent=track.hls||'';code.title=track.hls||'';
      pathCell.append(code);tr.append(pathCell);

      const actionsCell=ytTrackCell('td','Acciones','lab-track-actions');
      const buttons=document.createElement('div');buttons.className='lab-track-buttons';buttons.dataset.sfTrack=track.id;

      const details=document.createElement('details');details.className='sf-track-options';
      const summary=document.createElement('summary');summary.setAttribute('aria-label','Opciones de pista');summary.textContent='⋮';
      const optionsPanel=document.createElement('div');optionsPanel.className='sf-track-options-panel';
      const propsBtn=document.createElement('button');propsBtn.type='button';propsBtn.dataset.trackProperties=track.id;propsBtn.textContent='Propiedades';
      const reuseBtn=document.createElement('button');reuseBtn.type='button';reuseBtn.className='btn';reuseBtn.dataset.reuseSource=albumId;reuseBtn.dataset.reuseTrack=track.id;reuseBtn.textContent='Añadir a otra colección';
      const detachBtn=document.createElement('button');detachBtn.type='button';detachBtn.className='btn';detachBtn.dataset.detachTrack=track.id;detachBtn.dataset.detachAlbum=albumId;detachBtn.textContent='Quitar de esta colección';
      optionsPanel.append(propsBtn,reuseBtn,detachBtn);details.append(summary,optionsPanel);buttons.append(details);

      const play=document.createElement('a');play.className='lab-icon-btn lab-btn-play';play.title='Reproducir pista';play.setAttribute('aria-label','Reproducir '+(track.name||''));
      play.href='player.php?album='+encodeURIComponent(albumId)+'&i='+(index-1);
      play.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 5 12 7-12 7z" fill="currentColor" stroke="none"/></svg>';
      buttons.append(play);

      const edit=document.createElement('button');edit.type='button';edit.className='lab-icon-btn lab-btn-edit';edit.dataset.editTrack=track.id;
      edit.dataset.trackId=track.id;edit.dataset.trackName=track.name||'';edit.dataset.trackTitle=track.title||'';edit.dataset.trackArtist=track.artist||'';
      edit.dataset.trackAlbum=track.album||'';edit.dataset.trackGenre=track.genre||'';edit.dataset.trackYear=track.year||'';
      edit.title='Editar metadatos, portada y audio';
      edit.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><span>Editar</span>';
      buttons.append(edit);

      const del=document.createElement('button');del.type='button';del.className='lab-icon-btn lab-btn-delete delete-track-btn';del.title='Eliminar pista';
      del.dataset.trackId=track.id;del.dataset.trackName=track.name||'esta pista';
      del.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11l4 4m0-4-4 4"/></svg><span>Eliminar</span>';
      buttons.append(del);

      actionsCell.append(buttons);tr.append(actionsCell);
      tbody.append(tr);
      tr.classList.add('yt-row-new');
      window.setTimeout(()=>tr.classList.remove('yt-row-new'),4000);

      const heading=card.querySelector('.album-tracks-heading h2');
      if(heading)heading.textContent='Pistas ('+tbody.querySelectorAll('tr').length+')';
      document.dispatchEvent(new CustomEvent('sf:personal-changed'));
      return true;
    }
    let ytInspecting=false;
    window.sfAlbumImportBusy=()=>foregroundImport||ytInspecting||ytSubmitting;
    async function inspectYoutubeLinks(expected=[]){
      if(ytInspecting||foregroundImport||ytSubmitting||!albumId||!ytUrls)return false;
      const urls=[...new Set(ytUrls.value.split(/\r?\n/).map(value=>value.trim()).filter(Boolean))];if(!urls.length||urls.length>100){ytInspectStatus.textContent='Indica entre 1 y 100 enlaces.';return false;}
      const known=new Map([...ytInspected,...expected].map(item=>[ytVideoKey(item.url||item.webpage_url),item]));
      const results=urls.map(url=>{const item=known.get(ytVideoKey(url))||{};return {url,title:item.title||item.filename||'YouTube · '+ytVideoKey(url),filename:item.filename||item.title||'',pending:true,ok:false};});
      ytInspecting=true;ytInspectCancelled=false;ytInspectBtn.disabled=true;setYtImportBusy(true,'Verificando enlaces…');ytImportStatus.textContent='';const review=openYtReview();ytReviewAgain.hidden=false;review.setBusy(true);ytRenderResults(results);
      try{
        for(let index=0;index<urls.length&&!ytInspectCancelled;index++){
          ytInspectStatus.textContent='Verificando '+(index+1)+' de '+urls.length+' enlaces…';
          const fd=new FormData();fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');fd.set('action','inspect');fd.set('urls',urls[index]);ytAppendCookies(fd);
          const control=new AbortController();ytInspectControl=control;const timer=setTimeout(()=>control.abort(),60000);
          try{const response=await fetch('api_youtube.php',{method:'POST',body:fd,credentials:'same-origin',signal:control.signal});const data=await response.json();if(!response.ok||data.error)throw Error(data.error||'No se pudo verificar el enlace');const item=Array.isArray(data.results)?data.results[0]:null;if(!item||typeof item!=='object'||typeof item.ok!=='boolean')throw Error('El servidor no devolvió un resultado válido para el enlace');results[index]={...results[index],...item,pending:false};}
          catch(error){results[index]={...results[index],pending:false,ok:false,error:ytInspectCancelled?'Verificación cancelada':error.name==='AbortError'?'Tiempo agotado. Puedes volver a verificar este enlace.':error.message};}
          finally{clearTimeout(timer);if(ytInspectControl===control)ytInspectControl=null;}
          ytRenderResults(results);
        }
        for(let i=0;i<results.length;i++)if(results[i].pending)results[i]={...results[i],pending:false,ok:false,error:'Verificación cancelada'};
        ytRenderResults(results);const good=results.filter(item=>item.ok).length;ytInspectStatus.textContent=(ytInspectCancelled?'Verificación cancelada: ':'Verificación terminada: ')+good+' de '+urls.length+' enlaces válidos.';return !ytInspectCancelled&&good===urls.length;
      }finally{ytInspecting=false;ytInspectControl=null;review.setBusy(false);ytInspectBtn.disabled=false;setYtImportBusy(false);ytImportBtn.disabled=foregroundImport||!ytCurrentRows().some(row=>row.querySelector('.yt-include').checked);}
    }
    const ytReviewAgain=document.createElement('button');ytReviewAgain.type='button';ytReviewAgain.className='btn';ytReviewAgain.textContent='Ver resultados';ytReviewAgain.hidden=true;ytReviewAgain.onclick=()=>ytReview?.open();ytInspectBtn?.after(ytReviewAgain);
    ytResults?.addEventListener('change',()=>{ytImportBtn.disabled=ytInspecting||ytSubmitting||foregroundImport||!ytCurrentRows().some(row=>row.querySelector('.yt-include').checked);});
    if(ytInspectBtn&&albumId)ytInspectBtn.addEventListener('click',()=>inspectYoutubeLinks());
    document.addEventListener('sf-album-links-ready',async e=>{
      if(!albumId)return;
      if(!e.detail?.process){ytInspectStatus.textContent='Enlaces del álbum añadidos. Puedes editarlos y pulsar Inspeccionar enlaces.';return;}
      const good=await inspectYoutubeLinks(e.detail.tracks||[]);
      if(e.detail.autoImport&&good){ytImportBtn.click();return;}
      const rows=ytCurrentRows();
      if(!rows.length)return;
      ytInspectStatus.textContent=good?'Nombres preparados. Revisa y edita el nombre de cada pista y pulsa «Descargar e importar seleccionadas» para comenzar.':'Revisa los enlaces válidos, edita sus nombres y pulsa «Descargar e importar seleccionadas».';
      ytReview?.open();
    });
    function clearImportedYouTube(track){
      const row=ytCurrentRows().find(r=>r.dataset.url===track.url&&r.querySelector('.yt-filename')?.value.trim()===track.filename);if(!row)return;
      row.remove();const input=document.getElementById('ytUrls');if(input)input.value=input.value.split(/\r?\n/).filter(line=>line.trim()!==track.url).join('\n');
    }
    if (ytImportBtn && albumId) {
      ytImportBtn.addEventListener('click', async () => {
        if(ytInspecting||foregroundImport||ytSubmitting)return;
        const tracks = ytCurrentRows().filter(row => row.querySelector('.yt-include')?.checked).map(row => ({
          url: row.dataset.url,
          title: row.dataset.title,
          duration: Number(row.dataset.duration) || 0,
          filename: row.querySelector('.yt-filename')?.value.trim() || '',
          format_id: row.querySelector('.yt-source-format')?.value || ''
        }));
        if (!tracks.length) { alert('Selecciona al menos una pista.'); return; }
        if (tracks.some(t => !t.filename)) { alert('Todos los archivos deben tener nombre.'); return; }

        ytSubmitting=true;setYtImportBusy(true,'Revisando coincidencias…');ytReview?.close();
        // Un único barrido de coincidencias para todo el lote.
        let duplicateDecisions = new Map();
        if(!window.sfCheckDuplicates){ytSubmitting=false;setYtImportBusy(false);alert('No se cargó la comprobación de coincidencias. Recarga antes de importar.');return;}
        if (window.sfCheckDuplicates) {
          try {
            const entries = tracks.map((track, i) => ({
              key: String(i),
              name: track.filename || track.title,
              duration: Number(track.duration) || 0
            }));
            duplicateDecisions = await window.sfCheckDuplicates(entries, albumId, {});
          } catch (error) {
            alert(error.message || 'No se pudo revisar el lote. Vuelve a intentarlo.');ytSubmitting=false;setYtImportBusy(false);ytImportBtn.disabled=false;ytReview?.open();
            return;
          }
        }

        foregroundImport = true;foregroundEpoch++;dismissProgress.start();ytSubmitting=false;ytReview?.close();window.sfYouTubeReview.showProgress(panel);
        setYtImportBusy(true,'En proceso de descarga …');
        ytInspectBtn.disabled = true;
        ytImportStatus.replaceChildren();
        if (panel) {
          panel.hidden = false;
          // Llevar el indicador al área visible al comenzar la importación.
          // The floating progress panel leaves the page scroll position unchanged.
        }
        if (logEl) logEl.replaceChildren();
        const panelTitle = panel?.querySelector('h2');
        if (panelTitle) panelTitle.textContent = 'Importando desde YouTube…';
        setProgress(0, `Preparando ${tracks.length} pista(s)…`);
        let imported = 0;
        let failed = 0;
        let importCompleted=false;
        let needFullRefresh = false;
        const results = [];
        try {
          // Una petición por pista: cada resultado queda visible y un fallo no oculta
          // los resultados anteriores ni impide procesar las siguientes pistas.
          for (let i = 0; i < tracks.length; i++) {
            const track = tracks[i];
            const decision = duplicateDecisions.get(String(i)) || {mode:'new'};
            if (decision.mode === 'skip') {
              imported++;
              logLine('Omitida ' + track.filename + ' (coincidencia con una pista existente)', true);
              continue;
            }
            if (decision.mode === 'reuse' && decision.match) {
              try {
                await attachExistingTrack(decision.match, albumId);
                imported++;
                try{await window.sfRefreshAlbumTracks({appendOnly:true});}catch(error){needFullRefresh=true;logLine('Pista reutilizada; actualización de tabla pendiente: '+error.message,false);}
                clearImportedYouTube(track);const message = `OK ${i + 1}/${tracks.length}: reutilizada «${decision.match.name}»`;
                logLine(message, true);results.push(message);
              } catch (error) {
                failed++;
                const message = `ERROR ${i + 1}/${tracks.length}: ${track.filename} — ${error.message || 'no se pudo reutilizar'}`;
                logLine(message, false);results.push(message);
              }
              setProgress(((i + 1) / tracks.length) * 100, `Terminadas ${i + 1}/${tracks.length}; correctas: ${imported}; fallidas: ${failed}`);
              continue;
            }
            const replacement = decision.mode === 'replace' && decision.match ? decision.match : null;
            const importAlbum = replacement?.album_id || albumId;
            setTrackProgress(0);setProgress((i / tracks.length) * 100, `Procesando ${i + 1}/${tracks.length}: ${track.filename}`);
            const fd = new FormData();
fd.set('csrf_token',document.querySelector('meta[name="sf-csrf"]')?.content||'');
            fd.append('action', 'import');
            fd.append('album_id', importAlbum);
            if (replacement) { fd.append('track_id', replacement.id); fd.append('url', track.url); }
            fd.append('tracks', JSON.stringify([track]));
            ytAppendCookies(fd);
            try {
              // El reemplazo se procesa en el servidor porque reutiliza la pista
              // existente y conserva listas, favoritos y metadatos.
              const local=!replacement&&window.sfClientProcessing?.desktop?.()&&document.getElementById('ytLocalProcessing')?.checked;const response = local?await window.sfClientProcessing.youtube(track.url,track.filename,(p,text)=>{setTrackProgress(p);setProgress((i/tracks.length)*100,text);},{local:true,albumId,formatId:track.format_id,extraBody:fd}):await fetch(replacement?'api_replace_track.php':'api_youtube.php', {method:'POST', body:fd, credentials:'same-origin', headers:{'Accept':'application/json'}});
              const result = await response.json().catch(() => ({}));
              if (!response.ok || result.error) throw new Error(result.error || `HTTP ${response.status}`);
              let completedTrack=null;
              if(result.job_id)result.job_ids=[result.job_id];if (result.queued && Array.isArray(result.job_ids) && result.job_ids[0]) {
                const completed = await waitForProcessingJob(result.job_ids[0], job => setProgress((i / tracks.length) * 100, job.state === 'queued' ? `En cola (${job.ahead || 0} delante): ${track.filename}` : `${job.stage}: ${track.filename}`));
                completedTrack=completed.track||null;
                result.results = [{ok:true,title:completedTrack?.name || track.filename}];
              }
              const item = (result.results || [])[0];
              if (!item || !item.ok) throw new Error(item?.error || 'El servidor no confirmó que se añadiera la pista al álbum.');
              imported++;
              // Refresca la tabla al instante en vez de esperar la recarga final.
              // Una sustitución conserva la fila existente: se refresca completa.
              try{await window.sfRefreshAlbumTracks({appendOnly:!replacement,trackId:replacement?.id||null});}catch(error){needFullRefresh=true;logLine('La pista está guardada; actualización de la tabla pendiente: '+error.message,false);}
              clearImportedYouTube(track);const message = `OK ${i + 1}/${tracks.length}: ${item.title || track.filename}`;
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
          importCompleted=true;
          const summary = document.createElement('p');
          summary.className = failed ? 'err' : 'ok';
          summary.textContent = `Importación finalizada: ${imported} correcta(s), ${failed} fallida(s).`;
          ytImportStatus.appendChild(summary);
          if (failed) {
            const details = document.createElement('pre');
            details.style.whiteSpace = 'pre-wrap';
            details.textContent = results.filter(x => x.startsWith('ERROR')).join('\n');
            ytImportStatus.appendChild(details);
          }
        } catch(error){failed++;logLine('ERROR: '+(error.message||'No se pudo completar la importación.'),false);
        } finally {
          setYtImportBusy(false);ytImportBtn.disabled=!ytCurrentRows().some(row=>row.querySelector('.yt-include')?.checked);ytImportBtn.textContent=ytImportBtn.disabled?'Importación finalizada':'Reintentar pistas pendientes';ytInspectBtn.disabled=false;foregroundImport=false;ytSubmitting=false;
          if(panelTitle)panelTitle.textContent=failed?'Importación finalizada con avisos':'Importación finalizada';
          await scheduleAlbumRefresh();
          dismissProgress.finish({success:importCompleted&&failed===0});

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


<script src="assets/navigation.js?v=album-return1"></script>
<link rel="stylesheet" href="assets/player-icons.css?v=3"><script src="assets/player-icons.js?v=3"></script><script src="assets/shell-player.js?v=20261116-console1"></script>
<script src="assets/player-frame-fit.js?v=14"></script>
<script src="assets/audio-jobs.js?v=jobs-panel3"></script>

<script src="assets/cpu-fill.js?v=2"></script><script src="assets/container-memory.js?v=20261114-worker-node1"></script><script src="assets/replacement-notices.js?v=theme-sweep1"></script><link rel="stylesheet" href="assets/youtube-source-picker.css?v=2"><script src="assets/youtube-source-picker.js?v=local-upload3"></script><script src="assets/youtube-batch-search.js?mornye=2&v=mornye1"></script><script src="assets/track-replace.js?v=20261114-worker-node1"></script><?php if((lab_auth_current_account()['role']??'')==='admin'): ?><script src="assets/external-maintenance-notices.js?v=theme-sweep1"></script><?php endif; ?><script src="assets/loading-skeletons.js?v=1"></script><script src="assets/loose-track-editor.js?v=20261116-console1"></script><script src="assets/album-discovery.js?mornye=2&v=20261114-auto-import1"></script>
<script src="assets/personal-library.js?v=55"></script>
<script>(()=>{const button=document.getElementById('showAlbumInLibrary');if(!button)return;button.addEventListener('click',()=>{const id=button.dataset.album||'';if(window===window.parent){location.href='index.php?view=library'+(id?'#album-'+encodeURIComponent(id):'');return;}window.parent.postMessage({source:'sf-navega',type:'show-library',album:id},location.origin);});})();</script><script src="assets/mornye-picker.js?v=20261113-transfer"></script><link rel="stylesheet" href="assets/queue-actions.css?v=1"><script src="assets/queue-actions.js?v=membership1"></script></body>
</html>
<link rel="stylesheet" href="assets/mornye-progress.css?v=1"><script src="assets/task-resources.js?v=1"></script><script src="assets/mornye-prepare.js?v=1"></script><script src="assets/mornye-album-batch.js?v=20261114-progress-standard1"></script><script src="assets/mornye-album-editor.js?v=20261114-cleanup2"></script>
<script>if(new URLSearchParams(location.search).get('mornye')==='1'){window.addEventListener('load',()=>{const id=new URLSearchParams(location.search).get('id');if(!id)return;const b=document.createElement('button');b.hidden=true;b.dataset.addYoutube='';b.dataset.defaultSource='mornye';b.dataset.replaceAlbum=id;document.body.append(b);if(window.sfShowMornyeAlbumImporter){window.sfShowMornyeAlbumImporter();}b.remove();const u=new URL(location.href);u.searchParams.delete('mornye');history.replaceState(history.state,'',u);},{once:true});}</script>
