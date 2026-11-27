<?php
declare(strict_types=1);
require_once __DIR__.'/lib/management_view.php';
require __DIR__ . '/lib.php';
$cfg = lab_load_config();
require_once __DIR__ . '/lib/user_preferences.php';
$playerAccount = lab_auth_current_account();
$sfTheme = is_array($playerAccount) ? lab_user_theme((string)$playerAccount['id']) : LAB_THEME_DEFAULT;
$personalHlsProfile = is_array($playerAccount) ? lab_user_hls_profile((string)$playerAccount['id']) : LAB_HLS_DEFAULT_PROFILE;
$albumId = isset($_GET['album']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['album']) : '';
$album = null;
if ($albumId !== "") {
    try {
        require_once __DIR__ . "/lib/sqlite_catalog_read.php";
        $album = lab_sqlite_load_album($albumId);
    } catch (Throwable $e) {
        error_log("HLS Music Lab: lectura SQLite fallida; se usará JSON: " . $e->getMessage());
    }
    if ($album === null) {
        $album = lab_load_album($albumId);
    }
}
if(isset($_GET['playlist'])||isset($_GET['favorites'])){
 require_once __DIR__.'/lib/personal_library.php';require_once __DIR__.'/lib/sqlite_catalog_read.php';
 $uid=(string)($playerAccount['id']??'');$kind=isset($_GET['playlist'])?'playlist':'favorites';$pid=(string)($_GET['playlist']??'');
 try{$rows=sf_personal_tracks(lab_auth_pdo(),$uid,$kind,$pid);$cache=[];$queue=[];
 foreach($rows as $row){$aid=$row['album_id'];if(!array_key_exists($aid,$cache))$cache[$aid]=lab_sqlite_load_album($aid)??lab_load_album($aid);foreach($cache[$aid]['tracks']??[] as $candidate)if(($candidate['id']??'')===$row['id']){$queue[]=$candidate;break;}}
 $title=$kind==='favorites'?'Favoritos':sf_personal_access(lab_auth_pdo(),$uid,$pid)['name'];
 $album=['id'=>'personal-'.$kind.'-'.$uid.'-'.$pid,'title'=>$title,'artist'=>'Mi biblioteca','tracks'=>$queue];
 }catch(Throwable $e){http_response_code(404);exit(lab_h($e->getMessage()));}
}
require_once __DIR__.'/lib/track_reuse.php';
require_once __DIR__.'/lib/cover_url.php';
if($album && $albumId!=='' && !isset($_GET['playlist']) && !isset($_GET['favorites']))$album=sf_reuse_view(lab_load_album($albumId)??$album);
if($album&&!empty($album['music_chart'])){require_once __DIR__.'/lib/music_charts.php';$chartDb=lab_auth_pdo();$q=$chartDb->prepare('SELECT enabled FROM sf_music_charts WHERE chart_key=?');$q->execute([$album['music_chart']]);$enabled=(bool)$q->fetchColumn();$allowed=$enabled?array_column(array_filter(sfc_entry_rows($chartDb,$album['music_chart']),static fn($r)=>$r['state']==='ready'),'track_id'):[];if(!$enabled||$allowed)$album['tracks']=array_values(array_filter($album['tracks']??[],static fn($t)=>in_array($t['id']??'',$allowed,true)));}
$tracks = $album['tracks'] ?? [];
$layout = $album['cover_layout'] ?? ['x' => 50, 'y' => 50, 'zoom' => 100];
$coverUrl = sf_cover_url($album);
if (!$album && isset($_GET['src'])) {
    $src = str_replace(["\0", '\\'], '', (string) $_GET['src']);
    if ($src !== '' && !str_contains($src, '..') && str_starts_with($src, 'hls_out/')) {
        $tracks = [['name' => (string) ($_GET['title'] ?? 'Pista HLS'), 'hls' => $src, 'duration' => 0]];
        $album = ['title' => $tracks[0]['name'], 'artist' => 'HLS Music Lab', 'tracks' => $tracks];
    }
}
$startIndexExplicit = array_key_exists('i', $_GET);
$startIndex = max(0, (int) ($_GET['i'] ?? 0));
$embed = !empty($_GET['embed']);
if ($tracks && $startIndex >= count($tracks)) {
    $startIndex = 0;
}
if (array_key_exists('track_id', $_GET)) {
    $trackId = $_GET['track_id'];
    $match = is_string($trackId) && $trackId !== ''
        ? array_search($trackId, array_map(static fn($track) => $track['id'] ?? null, $tracks), true)
        : false;
    if ($match === false) {
        http_response_code(404);
        exit('La canción seleccionada ya no está disponible en esta colección.');
    }
    $startIndex = $match;
    $startIndexExplicit = true;
}
require_once __DIR__.'/lib/loudness.php';
if($album)$album['tracks']=sf_loudness_attach($album['tracks']??[]);
require_once __DIR__.'/lib/playback_diagnostics.php';
$payload = [
    'playbackDiagnostics' => sf_playback_debug_settings($cfg),
    'accountId' => (string)($playerAccount['id']??''),
    'normalizeVolume' => sf_loudness_enabled((string)($playerAccount['id']??'')),
    'album' => $album,
    'prefetchNext' => $albumId !== '' && $albumId !== 'fffffffffffffffe' && !isset($_GET['playlist'], $_GET['favorites']) && !isset($_GET['playlist']) && !isset($_GET['favorites']) && ($_GET['prefetch'] ?? '1') !== '0',
    'canReuse' => lab_ui_can_manage_albums() && !isset($_GET['playlist']) && !isset($_GET['favorites']),
    'lrclib' => !empty($cfg['lrclib_enabled']),
    'hlsProfile' => $personalHlsProfile,
    'startIndex' => $startIndex,
    'startIndexExplicit' => $startIndexExplicit,
    'localCover' => $coverUrl,
    'embed' => $embed,
] ;
if(($_GET['payload']??'')==='1'){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
?><!DOCTYPE html>
<html lang="es" data-theme="<?= lab_h($sfTheme) ?>" data-theme-source="server">
<head>
  <meta charset="UTF-8">
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <meta name="application-name" content="Sparrow-Fy · HLS Music Lab">
  <meta name="theme-color" content="<?= lab_h(lab_theme_meta_color($sfTheme)) ?>" id="themeColorMeta">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= lab_h($album['title'] ?? 'Reproductor') ?> · Lab</title>
  <script src="assets/hls-1.5.17.min.js"></script>
  <link rel="stylesheet" href="assets/lab.css?v=theme-sweep1">
  <link rel="stylesheet" href="assets/sparrowfy-theme.css?v=theme-sweep1">
  <link rel="stylesheet" href="assets/player.css?v=cover-stable1">
  <link rel="stylesheet" href="assets/sparrowfy-theme.css?v=theme-sweep1">
<meta name="sf-csrf" content="<?= lab_h(lab_auth_csrf_token()) ?>">
<link rel="stylesheet" href="assets/personal-library.css?v=theme-sweep1">

<script src="assets/membership-picker.js?v=floating1"></script><link rel="stylesheet" href="assets/membership-picker.css?v=theme-sweep1">
<script src="assets/artwork-backup.js?v=20261116-console1" defer></script><meta name="sf-account" content="<?=lab_h((string)(lab_auth_current_account()['id']??''))?>"><script src="assets/offline-store.js?v=offline-state2" defer></script><script src="assets/offline-quality.js?v=quality1" defer></script><script src="assets/offline-downloads.js?v=offline-state2" defer></script><link rel="stylesheet" href="assets/save-feedback.css?v=2"><script src="assets/save-feedback.js?v=2"></script><script defer src=assets/sf-language.js></script><style>#btnFloat[hidden]{display:none!important}</style>
</head>
<body class="player-body<?= $embed ? ' is-embed' : '' ?>">
  <?php if (!$embed): ?>
  <a class="back-link" href="<?= $albumId ? 'album_edit.php?id=' . lab_h(urlencode($albumId)) : 'index.php?view=library' ?>">← Volver</a>
  <?php endif; ?>

  <?php if (!$tracks): ?>
    <p class="err-banner">No hay pistas. <a href="index.php">Inicio</a></p>
  <?php else: ?>
  <audio id="audioPlayer" preload="auto"></audio>
  <div class="music-player" id="playerRoot">
    <div class="win-chrome">
      <div class="player-top-actions"><div id="sf-player-personal" class="sf-track-actions"></div><button type="button" class="eq-toggle" id="eqToggle" title="Ecualizador">EQ</button></div>
      <?php if ($embed): ?><button type="button" class="win-btn" id="btnFloat" title="Separar reproductor" aria-label="Separar reproductor" aria-pressed="false" hidden><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 4h6v6m0-6-9 9m7 0v6H5V6h6"/></svg></button><?php endif; ?>
      <button type="button" class="win-btn win-min" id="btnMin" title="Minimizar"><svg class="sf-media-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 6l4 4 4-4"/><path d="M5 18h14"/></svg></button>
      <button type="button" class="win-btn win-max" id="btnMax" title="Karaoke (maximizar letras)"><svg class="sf-media-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/><path stroke-width="1.8" d="M9 9.5h6M9 14.5h6"/></svg></button>
      <button type="button" class="win-btn win-close" id="btnClose" title="Cerrar"><svg class="sf-media-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6.5 6.5 11 11m0-11-11 11"/></svg></button>
    </div>

    <div class="track-info track-info-row">
      <img class="track-art" id="trackArt" alt="" width="56" height="56" hidden>
      <div class="track-text">
        
        <h2 class="track-title" id="trackTitle"><?= lab_h($tracks[0]['name'] ?? '') ?></h2>
        <p class="track-artist" id="trackStatus"><?= lab_h(($album['artist'] ?? '') !== '' ? $album['artist'] : ($album['title'] ?? 'Lab')) ?></p>
      </div>
    </div>

    <div class="progress-container">
      <div class="progress-bar-bg" id="progressBarBg"><div class="progress-bar" id="progressBar"></div></div>
      <div class="time-row">
        <span id="currentTime">00:00</span>
        <span id="durationTime">00:00</span>
      </div>
    </div>

    <div class="controls-row">
      <button type="button" class="btn btn-round" id="shuffleBtn" title="Reproducción continua" aria-label="Activar reproducción aleatoria" aria-pressed="false"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h3c5 0 7 12 12 12h3m-4-4 4 4-4 4M3 18h3c2 0 3-2 4-4m4-4c1-2 2-4 4-4h3m-4-4 4 4-4 4"/></svg></button>
<button type="button" class="btn btn-round" id="repeatQueueBtn" title="Repetir cola: desactivado" aria-label="Activar repetición de toda la cola" aria-pressed="false"><svg class="sf-media-icon" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m17 2 4 4-4 4M3 11V9a3 3 0 0 1 3-3h15M7 22l-4-4 4-4m14-1v2a3 3 0 0 1-3 3H3"/></svg></button>
      <button type="button" class="btn btn-round" id="restartBtn" title="Reiniciar melodía" aria-label="Reiniciar melodía"><svg class="sf-media-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 10a8 8 0 1 1 1 8M4 4v6h6"/></svg></button>
      <button type="button" class="btn btn-round" id="prevBtn" title="Anterior" aria-label="Pista anterior"><svg class="sf-media-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path fill="currentColor" stroke="none" d="M5 4h3v16H5zm14 0L8 12l11 8z"/></svg></button>
      <button type="button" class="btn btn-round btn-play" id="playPauseBtn" title="Play/Pausa" aria-label="Reproducir" aria-pressed="false"><svg class="sf-media-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path fill="currentColor" stroke="none" d="M7 4.5v15l12-7.5z"/></svg></button>
      <button type="button" class="btn btn-round" id="nextBtn" title="Siguiente" aria-label="Pista siguiente"><svg class="sf-media-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path fill="currentColor" stroke="none" d="M16 4h3v16h-3zM5 4v16l11-8z"/></svg></button>
    </div>

    <div class="footer-controls">
      <div class="volume-container">
        <button type="button" class="btn" id="muteBtn"><svg class="sf-media-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M11 5 6 9H3v6h3l5 4zM15 8a6 6 0 0 1 0 8m3-11a10 10 0 0 1 0 14"/></svg></button>
        <input type="range" class="volume-slider" id="volumeSlider" min="0" max="1" step="0.05" value="1">
      </div>
      <select class="quality-select" id="qualitySelect"><option value="-1">Auto</option></select>
    </div>

    <div class="eq-lyrics-row">
      <label title="Ajusta la sonoridad sin compresión"><input type="checkbox" id="normalizeVolume"> Normalizar</label> 
      <label class="lyrics-toggle">
        <input type="checkbox" id="lyricsCheck"> Mostrar letra
        <span class="lyrics-source" id="lyricsSource" hidden></span>
      </label>
    </div>
    <div class="eq-panel" id="eqPanel" hidden>
      <div class="eq-row"><label>Graves</label><input type="range" id="eqBass" min="-12" max="12" step="1" value="0"><span id="eqBassVal">0</span></div>
      <div class="eq-row"><label>Medios</label><input type="range" id="eqMid" min="-12" max="12" step="1" value="0"><span id="eqMidVal">0</span></div>
      <div class="eq-row"><label>Agudos</label><input type="range" id="eqTreble" min="-12" max="12" step="1" value="0"><span id="eqTrebleVal">0</span></div>
      <button type="button" class="btn" id="eqReset">Restablecer</button>
    </div>

    <details class="sf-player-tools" id="queueTools"><summary>Cola y reproducción</summary><div class="sf-player-tools-body"><label>Buscar y añadir a la cola<input type="search" id="queueSearch" placeholder="Título de la canción" maxlength="100"></label><button type="button" class="btn" id="queueSearchButton">Buscar</button><div id="queueSearchResults" aria-live="polite"></div><label>Guardar cola como lista<input type="text" id="queueSaveName" maxlength="120" placeholder="Nombre de la lista"></label><button type="button" class="btn" id="queueSaveButton">Guardar lista</button><label>Repetición<select id="queueRepeat"><option value="all">Toda la cola</option><option value="one">Una canción</option><option value="off" selected>Sin repetir</option></select></label><label>Temporizador<select id="playerSleep"><option value="0">Desactivado</option><option value="end">Al terminar esta canción</option><option value="5">5 minutos</option><option value="15">15 minutos</option><option value="30">30 minutos</option><option value="60">60 minutos</option><option value="90">90 minutos</option></select></label><p id="playerSleepStatus" role="status"></p><p id="queueActionStatus" role="status"></p><button type="button" class="btn" id="offlineDownloadCurrent">⤓ Oír sin conexión</button><a class="btn" href="offline.html" target="_top">Abrir Oír sin conexión</a><p class="muted">Sin conexión: guarda una copia en este equipo (límite inicial 200 MiB). Cerrar sesión borra las descargas locales.</p><p id="offlineDownloadStatus" role="status"></p><p class="muted">Arrastra para ordenar en escritorio o usa las flechas del menú de cada pista. Quitar de la cola no elimina música.</p></div></details>
<div class="playlist-wrap" id="playlistWrap">
      <ul class="playlist" id="playlist"></ul>
      <p class="muted playlist-hint">Doble clic / doble toque para cambiar de pista</p>
    </div>

    <div class="lyrics-box" id="lyricsBox" hidden></div>

    <div class="net-overlay" id="netOverlay" hidden>
      <div class="net-card">
        <div class="net-spinner" id="netSpinner" aria-hidden="true"></div>
        <p class="net-msg" id="netMsg">Reconectando…</p>
        <button type="button" class="btn primary" id="netRetryBtn" hidden>Reintentar</button>
      </div>
    </div>
  </div>

  <div class="mini-dock" id="miniDock" hidden>
    <img class="mini-art" id="miniArt" alt="" width="40" height="40" hidden>
    <div class="mini-meta">
      <strong id="miniTitle">—</strong>
      <span id="miniStatus">Mini</span>
    </div>
    <button type="button" class="btn btn-round btn-play mini-play" id="miniPlay"><svg class="sf-media-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path fill="currentColor" stroke="none" d="M7 4.5v15l12-7.5z"/></svg></button>
    <button type="button" class="btn win-btn" id="miniRestore" title="Restaurar"><svg class="sf-media-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/></svg></button>
  </div>

  <div class="karaoke-overlay" id="karaokeOverlay" hidden>
    <div class="karaoke-actions"><button type="button" class="btn" id="karaokeFullscreen" aria-label="Karaoke a pantalla completa" title="Pantalla completa para leer las letras a distancia; Esc para salir"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/></svg></button><button type="button" class="btn" id="karaokeClose">✕</button></div>
    <div class="karaoke-lyrics" id="karaokeLyrics"></div>
  </div>

  <div class="lib-panel" id="libPanel" hidden>
    <div class="lib-panel-head">
      <strong>Biblioteca</strong>
      <button type="button" class="btn" id="libRestorePlayer">Volver al reproductor</button>
    </div>
    <div class="lib-panel-body" id="libPanelBody">Cargando…</div>
  </div>

  <script>
    window.LAB_PAYLOAD = <?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
  
<div id="albumCtxMenu" class="ctx-menu" hidden>
  <button type="button" data-act="offline">⤓ Oír sin conexión</button>
  <button type="button" data-act="match">Coincidir álbum…</button>
  <button type="button" data-act="frame">Encuadrar portada…</button>
  <button type="button" data-act="edit">Gestionar pistas</button>
  <button type="button" data-act="delete" class="danger">Eliminar álbum…</button>
</div>
<div id="matchModal" class="modal-overlay" hidden>
  <div class="modal-card" role="dialog">
    <div class="modal-head">
      <h3>Coincidir álbum</h3>
      <button type="button" class="modal-x" id="matchClose">×</button>
    </div>
    <p class="muted" id="matchHint"></p>
    <div id="matchGrid" class="match-grid"></div>
    <div class="dialog-actions"><button type="button" class="btn" id="matchCancel">Cancelar</button></div>
  </div>
</div>
<div id="frameModal" class="modal-overlay" hidden>
  <div class="modal-card" role="dialog">
    <div class="modal-head">
      <h3>Encuadrar portada</h3>
      <button type="button" class="modal-x" id="frameClose">×</button>
    </div>
    <div id="framePreview" class="cover-preview"></div>
    <label>Horizontal</label><input type="range" id="frameX" min="0" max="100" value="50">
    <label>Vertical</label><input type="range" id="frameY" min="0" max="100" value="50">
    <label>Zoom</label><input type="range" id="frameZ" min="50" max="200" value="100">
    <div class="dialog-actions">
      <button type="button" class="btn" id="frameCancel">Cancelar</button>
      <button type="button" class="btn primary" id="frameSave">Guardar</button>
    </div>
  </div>
</div>
<script src="assets/library.js?v=favalb1"></script>
<script src="assets/navigation.js?v=album-return1"></script>
<script src="assets/personal-library.js?v=floating1"></script>
<link rel="stylesheet" href="assets/player-tools.css?v=3"><script src="assets/playback-diagnostics.js?v=ios-hls1"></script><script src="assets/next-buffer.js?v=2"></script><link rel="stylesheet" href="assets/player-icons.css?v=3"><script src="assets/player-icons.js?v=20261113-repeat2"></script><script src="assets/player.js?v=floating2"></script>
<script src="assets/player-queue-presence.js?v=1"></script><script src="assets/player-content-size.js?v=compact-elastic1"></script>

  <?php endif; ?>
<script src="assets/usage-heartbeat.js"></script><link rel="stylesheet" href="assets/queue-actions.css?v=1"><script src="assets/queue-actions.js?v=membership1"></script></body>
</html>
