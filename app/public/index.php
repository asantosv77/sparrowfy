<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
$cfg = lab_load_config();
require_once __DIR__.'/lib/user_preferences.php';
$sfMemUser = lab_auth_can_manage_albums() ? lab_user_client_memory((string)(lab_auth_current_account()['id'] ?? '')) : 0;
$sfMemEffective = $sfMemUser > 0 ? $sfMemUser : (int)($cfg['client_memory_mib'] ?? 0);
require_once __DIR__.'/lib/track_reuse.php';
require_once __DIR__.'/lib/track_menu.php';
$view = (string) ($_GET['view'] ?? 'home');
if(!in_array($view,['home','library','tracks','artists','favorites','recent','playlists','offline'],true))$view='home';
require_once __DIR__.'/library_pagination.php';
require_once __DIR__.'/lib/library_root.php';require_once __DIR__.'/lib/loose_order.php';
$searchTerm=trim((string)($_GET['q']??''));$catalogPage=null;
if(in_array($view,['library','tracks'],true)){
 require_once __DIR__.'/lib/catalog_page.php';
 try{$catalogPage=sf_catalog_pages($view,$searchTerm);}catch(Throwable $e){error_log('SparrowFy catalog pagination fallback: '.$e->getMessage());}
}
if($catalogPage!==null){
 extract($catalogPage,EXTR_OVERWRITE);
 $allTracks=$loosePage['items'];$libraryTracks=$trackPage['items'];$matchingTracks=$trackPage['items'];$matchingAlbums=$albumPage['items'];$artists=[];$recentItems=[];
}else{
$albums = in_array($view,['favorites','recent','playlists','offline'],true) ? [] : sf_reuse_views(lab_list_albums());
require_once __DIR__.'/lib/album_deletion.php';
$albums = sf_delreq_filter_visible($albums);
if($view==='library')$albums=array_values(array_filter($albums,static fn($a)=>empty($a['music_chart'])));
// Solo pistas sueltas (sin álbum). Las de un álbum se oyen desde la ficha del álbum.
require_once __DIR__.'/lib/library_root.php';
$allTracks = [];
foreach($albums as $rootAlbum)if(sf_is_loose($rootAlbum))foreach($rootAlbum['tracks']??[] as $i=>$t){$t['album_id']=$rootAlbum['id'];$t['album_title']='Pistas sin asignar a colección';$t['index']=$i;$allTracks[]=$t;}
$albumTrackCount = 0;
foreach ($albums as $a) {
    if(!sf_is_loose($a))$albumTrackCount += count($a['tracks'] ?? []);
}

$searchTerm = trim((string)($_GET['q'] ?? ''));
$libraryTracks = [];
$artists = [];
if(in_array($view,['tracks','artists'],true)){
foreach ($albums as $a) {
    $artistName = trim((string)($a['artist'] ?? ''));
    if ($artistName === '') $artistName = 'Artista desconocido';
    $artistKey = function_exists('mb_strtolower') ? mb_strtolower($artistName, 'UTF-8') : strtolower($artistName);
    if (!isset($artists[$artistKey])) $artists[$artistKey] = ['name' => $artistName, 'albums' => [], 'tracks' => 0];
    $artists[$artistKey]['albums'][] = $a;
    foreach (($a['tracks'] ?? []) as $i => $t) {
        if($view==='tracks')$libraryTracks[] = ['album' => $a, 'track' => $t, 'index' => (int)$i];
        $artists[$artistKey]['tracks']++;
    }
}
$artists = array_values($artists);
usort($artists, static function (array $a, array $b): int { return strcasecmp($a['name'], $b['name']); });
}
$matchingAlbums = [];
$matchingTracks = [];
if ($view === 'library' && $searchTerm !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($searchTerm, 'UTF-8') : strtolower($searchTerm);
    $contains = static function (string $value) use ($needle): bool {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return sf_search_match($value,$needle);
    };
    foreach ($albums as $a) {
        $albumTitle = (string)($a['title'] ?? '');
        $albumArtist = (string)($a['artist'] ?? '');
        $albumMatches = $contains($albumTitle) || $contains($albumArtist);
        if ($albumMatches) $matchingAlbums[] = $a;
        foreach (($a['tracks'] ?? []) as $i => $t) {
            $trackName = (string)($t['name'] ?? $t['title'] ?? '');
            $original = (string)($t['original'] ?? $t['original_name'] ?? '');
            if ($albumMatches || $contains($trackName) || $contains($original)) {
                $matchingTracks[] = ['album' => $a, 'track' => $t, 'index' => (int)$i];
            }
        }
    }
}
if ($view === 'tracks' && $searchTerm !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($searchTerm, 'UTF-8') : strtolower($searchTerm);
    $libraryTracks = array_values(array_filter($libraryTracks, static function (array $r) use ($needle): bool {
        $t = $r['track']; $a = $r['album'];
        $haystack = implode(' ', [(string)($t['name'] ?? $t['title'] ?? ''), (string)($t['original'] ?? ''), (string)($a['title'] ?? ''), (string)($a['artist'] ?? '')]);
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
        return sf_search_match($haystack,$needle);
    }));
}
if ($view === 'artists' && $searchTerm !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($searchTerm, 'UTF-8') : strtolower($searchTerm);
    $artists = array_values(array_filter($artists, static function (array $a) use ($needle): bool {
        $name = function_exists('mb_strtolower') ? mb_strtolower($a['name'], 'UTF-8') : strtolower($a['name']);
        return sf_search_match($name,$needle);
    }));
}
$albums=array_values(array_filter($albums,fn($a)=>!sf_is_loose($a)));
$matchingAlbums=array_values(array_filter($matchingAlbums,fn($a)=>!sf_is_loose($a)));
require_once __DIR__.'/library_pagination.php';
$albumPage=sf_page($searchTerm!==''?$matchingAlbums:$albums,'albums_page');
$allTracks=sf_loose_sort($allTracks,$view==='home'?'recent':sf_loose_order());$loosePage=sf_page($allTracks,'loose_page');
$trackPage=sf_page($view==='tracks'?$libraryTracks:$matchingTracks,'tracks_page');
$recentItems=[];
if($view==='home'){
foreach($albums as $a)if(empty($a['music_chart']))$recentItems[]=['album'=>$a,'date'=>strtotime($a['created_at']??'')?:0];
foreach($allTracks as $t){
 $date=sf_loose_stamp($t);
 if(!$date&&preg_match('/_(\d{8})_(\d{6})_/',(string)($t['job_id']??''),$m)){
  $parsed=DateTimeImmutable::createFromFormat('!YmdHis',$m[1].$m[2]);$date=$parsed?$parsed->getTimestamp():0;
 }
 $recentItems[]=['track'=>$t,'date'=>$date];
}
usort($recentItems,static fn($a,$b)=>$b['date']<=>$a['date']);
}
}
require_once __DIR__.'/lib/home_cards.php';
if($view==='home'){
 $homeLooseItems=array_values(array_filter($recentItems,static fn($item)=>isset($item['track'])));
 $recentItems=array_values(array_filter($recentItems,static fn($item)=>isset($item['album'])));
}
if($view==='home'&&isset($_GET['home_cards'])){sf_home_cards(array_slice($recentItems,0,max(1,min(1000,(int)$_GET['home_cards']))));exit;}
$hasManagementPermission = lab_auth_can_manage_albums();
$canManageAlbums = lab_ui_can_manage_albums();
$isAdmin = lab_auth_is_admin();
$sfTheme = lab_user_theme((string)(lab_auth_current_account()['id'] ?? ''));
$sfThemeColor = lab_theme_meta_color($sfTheme);
?><!DOCTYPE html>
<html lang="es" data-management-view="<?= $canManageAlbums ? '1' : '0' ?>" data-theme="<?= lab_h($sfTheme) ?>" data-theme-source="server">
<head>
  <meta charset="utf-8">
  <meta name="sf-csrf" content="<?= lab_h(lab_auth_csrf_token()) ?>">
  <meta name="sf-client-memory-mib" content="<?= (int)$sfMemEffective ?>">
  <meta name="sf-webcodecs-enabled" content="<?= !empty($cfg['webcodecs_enabled'])?'1':'0' ?>"><meta name="sf-client-memory-default-mib" content="<?= (int) ($cfg['client_memory_mib'] ?? 0) ?>">
  <link rel="stylesheet" href="assets/personal-library.css?v=favalb1">
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <meta name="application-name" content="HLS Music Lab">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="<?= lab_h($sfThemeColor) ?>" id="themeColorMeta">
  <script>try{document.documentElement.dataset.theme=<?= json_encode($sfTheme) ?>;document.documentElement.classList.add(sessionStorage.getItem("sparrowfySplashSeen")==="1"?"sparrow-splash-seen":"sparrow-splash-enabled")}catch(e){document.documentElement.dataset.theme=<?= json_encode($sfTheme) ?>}</script>
  <link rel="stylesheet" href="assets/splash.css?v=theme-sweep1">
  <title>Sparrow-Fy · HLS Music Lab</title>
  <link rel="stylesheet" href="assets/lab.css?v=theme-sweep1">
<script src="assets/membership-picker.js?v=1"></script><link rel="stylesheet" href="assets/membership-picker.css?v=theme-sweep1">
<meta name="sf-player-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES, 'UTF-8') ?>"><link rel="stylesheet" href="assets/layout-choice.css?v=1"><script src="assets/layout-choice.js?v=2" defer></script><link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="assets/pwa-180.png"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><link rel="stylesheet" href="assets/about-pwa.css?v=1"><script src="assets/about-pwa.js?v=3" defer></script><style>
.loose-track-list>li{display:grid!important;grid-template-columns:minmax(160px,1fr) auto;align-items:center;gap:10px 20px;padding:9px 0!important;text-align:left!important}.loose-track-list .job-meta{min-width:0;text-align:left!important;align-items:flex-start}.loose-track-list .job-meta strong{overflow-wrap:anywhere}.loose-track-list .job-meta>.muted{font-size:.8rem}.loose-track-list .job-actions{display:flex!important;align-items:center;justify-content:flex-end!important;gap:6px;flex-wrap:wrap;margin:0!important}.loose-track-list .job-actions>label{display:inline-flex;align-items:center;gap:5px;margin:0;font-size:.8rem}.loose-track-list select{max-width:200px;padding:5px;border-radius:7px;background:var(--panel,#142d37);color:var(--text);border:1px solid var(--border,#315563)}.loose-track-list .btn{padding:6px 10px}.loose-track-list .job-meta{display:flex;flex-direction:column;gap:2px}@media(max-width:900px){.loose-track-list>li{grid-template-columns:1fr}.loose-track-list .job-actions{justify-content:flex-start!important}}.sf-home-loose-section>h3:first-child{margin-top:0}
</style><style>.loose-track-list .loose-track-meta{display:grid;grid-template-columns:44px minmax(0,1fr);gap:2px 10px;align-items:center}.loose-artwork{grid-row:1/3;width:44px;height:44px;display:grid;place-items:center;border-radius:7px;background:var(--panel,#17313c);color:var(--muted,#9ab2ba);overflow:hidden}.loose-artwork img{width:100%;height:100%;object-fit:cover}.loose-track-meta>strong,.loose-track-meta>.muted{grid-column:2}</style><link rel="stylesheet" href="assets/management-view.css?v=1"><script src="assets/management-view.js?v=1" defer></script><link rel="stylesheet" href="assets/mobile-interface.css?v=5"><script src="assets/mobile-menu.js?v=close2" defer></script><script src="assets/loose-title-preview.js?v=3" defer></script><script src="assets/artwork-backup.js?v=20261116-console1" defer></script><meta name="sf-account" content="<?=lab_h((string)(lab_auth_current_account()['id']??''))?>"><script src="assets/offline-store.js?v=offline-state2" defer></script><script src="assets/offline-quality.js?v=quality1" defer></script><script src="assets/offline-downloads.js?v=offline-state2" defer></script><link rel="stylesheet" href="assets/offline-page.css?v=quality1"><script src="assets/offline-page.js?v=offline-state2" defer></script><link rel="stylesheet" href="assets/loading-skeletons.css?v=1"><script src="assets/audio-download.js?v=1" defer></script><link rel="stylesheet" href="assets/audio-download.css?v=1">
<meta name="sf-session-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><script src="assets/session-sync.js?v=auth-poll3" defer></script>
<link rel="stylesheet" href="assets/music-charts.css?v=album-cards1"><link rel="stylesheet" href="assets/sidebar-compact.css?v=1"><link rel="stylesheet" href="assets/save-feedback.css?v=2"><script src="assets/save-feedback.js?v=2"></script><script defer src=assets/sf-language.js></script></head>
<body>
<div class="sparrow-splash" id="sparrowSplash" role="status" aria-label="Cargando Sparrow-Fy">
  <div class="sparrow-splash__content">
    <div class="sparrow-splash__halo" aria-hidden="true"></div>
    <img class="sparrow-splash__logo" src="assets/sparrowfy-emblem.webp" alt="" width="178" height="178" fetchpriority="high">
    <p class="sparrow-splash__title">SPARROW-FY</p>
    <p class="sparrow-splash__subtitle">HLS Music Lab</p>
    <div class="sparrow-splash__wave" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
    <span class="sparrow-splash__status">PREPARANDO TU MÚSICA</span>
    <button class="sparrow-splash__skip" type="button" data-splash-skip>Omitir presentación</button>
  </div>
</div>
  <div class="app-shell">
  <header class="lab-header">
    <a class="sparrow-brand" href="#acerca-de" data-about aria-label="Acerca de Sparrow-Fy"><img class="sparrow-brand__logo" src="assets/sparrowfy-emblem.webp" alt="" width="42" height="42"><span class="sparrow-brand__text"><strong>SPARROW-FY</strong><small>HLS Music Lab</small></span></a>
    <form class="top-search" method="get" action="index.php" role="search">
      <input type="hidden" name="view" value="library"><input type="hidden" name="per_page" value="<?= $albumPage['size'] ?>">
      <label class="sr-only" for="globalLibrarySearch">Buscar canciones, artistas o álbumes</label>
      <span aria-hidden="true">⌕</span><input id="globalLibrarySearch" type="search" name="q" value="<?= $view === 'library' ? lab_h($searchTerm) : '' ?>" placeholder="Buscar canciones, artistas, álbumes…" maxlength="120" autocomplete="off">
      <button type="submit" aria-label="Buscar">↵</button>
    </form>
    <button type="button" class="sf-mobile-menu-toggle" id="sfMobileMenuToggle" aria-label="Abrir menú de navegación" aria-expanded="false" aria-controls="mobileNavigation"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
    <div class="header-actions">
      <button type="button" class="btn sf-continue-player" id="sfContinuePlayer" title="Continuar la última cola de reproducción" disabled><span aria-hidden="true">▶</span> Continuar</button>
      <?php if ($hasManagementPermission): ?><a class="btn primary" href="album_edit.php#createForm">＋ Nuevo álbum</a><?php endif; ?>
      <?php if ($isAdmin): ?><a class="icon-btn" href="index.php?view=config" aria-label="Configuración" title="Configuración">⚙</a><a class="icon-btn" href="admin_users.php" aria-label="Cuentas" title="Cuentas">♙</a><?php endif; ?>
      <form method="post" action="logout.php" style="display:inline;margin:0"><input type="hidden" name="csrf_token" value="<?= lab_h(lab_auth_csrf_token()) ?>"><button class="btn" type="submit">Salir · <?= lab_h((string)($_SESSION['username'] ?? '')) ?></button></form>
    </div>
  </header>

  <button type="button" class="sf-mobile-menu-backdrop" id="sfMobileMenuBackdrop" aria-label="Cerrar menú" tabindex="-1" hidden></button>
  <nav id="mobileNavigation" class="lab-nav" aria-label="Navegación principal">
    <a class="<?= $view === 'home' ? 'active' : '' ?>" href="index.php"><span class="nav-icon">⌂</span> Inicio</a>
    <a class="<?= $view === 'library' ? 'active' : '' ?>" href="index.php?view=library"><span class="nav-icon">▦</span> Biblioteca musical</a>
    <a class="<?= $view==='favorites'?'active':'' ?>" href="index.php?view=favorites"><span class="nav-icon">★</span> Favoritos</a>
    <a class="<?= $view==='recent'?'active':'' ?>" href="index.php?view=recent"><span class="nav-icon">◷</span> Recientes</a>
    <a class="<?= $view==='playlists'?'active':'' ?>" href="index.php?view=playlists"><span class="nav-icon">☷</span> Mis listas</a>
    <a class="<?= $view === 'offline' ? 'active' : '' ?>" href="index.php?view=offline"><span class="nav-icon">⤓</span> Oír sin conexión</a>
    <a class="<?= $view === 'tracks' ? 'active' : '' ?>" href="index.php?view=tracks"><span class="nav-icon">♫</span> Canciones</a>
    <a class="<?= $view === 'artists' ? 'active' : '' ?>" href="index.php?view=artists"><span class="nav-icon">♬</span> Artistas</a>
    <a href="index.php?view=library#albums"><span class="nav-icon">▣</span> Álbumes</a>
    <div class="nav-divider"></div>
    <span class="nav-section-label">TU CUENTA</span>
    <a href="communication.php"><span class="nav-icon">✉</span> Sugerencias<span data-com-unread></span></a>
    <a href="change_password.php"><span class="nav-icon">◉</span> Mi cuenta</a>
    <?php if ($isAdmin): ?><a class="<?= $view === 'config' ? 'active' : '' ?>" href="config.php"><span class="nav-icon">⚙</span> Configuración</a><a href="admin_users.php"><span class="nav-icon">♙</span> Cuentas de usuario</a><?php endif; ?>
    <?php if ($hasManagementPermission): ?><div class="nav-divider nav-new-album-mobile"></div><a class="nav-new-album-mobile" href="album_edit.php#createForm"><span class="nav-icon">＋</span> Nuevo álbum</a><?php endif; ?>
    <details class="theme-menu" id="themeMenu">
      <summary><span class="nav-icon" aria-hidden="true">◐</span><span>Temas y colores</span></summary>
      <div class="theme-menu__panel" role="group" aria-label="Elegir tema visual">
        
<p class="theme-menu__title sf-theme-desktop">Distribución</p>
<button type="button" class="theme-choice sf-theme-desktop" data-layout-choice="classic" aria-pressed="true"><span aria-hidden="true">▥</span><span><strong>Clásica</strong><small>Barra lateral · Actual</small></span><span class="theme-check" aria-hidden="true">✓</span></button>
<button type="button" class="theme-choice sf-theme-desktop" data-layout-choice="panorama" aria-pressed="false"><span aria-hidden="true">▤</span><span><strong>Panorámica</strong><small>Menú superior · Más espacio</small></span><span class="theme-check" aria-hidden="true">✓</span></button>
<p class="theme-menu__title sf-theme-mobile">Menú en móvil</p>
<button type="button" class="theme-choice sf-theme-mobile" data-mobile-menu-choice="current" aria-pressed="true"><span aria-hidden="true">▤</span><span><strong>Actual</strong><small>Barra de opciones · Predeterminado</small></span><span class="theme-check" aria-hidden="true">✓</span></button>
<button type="button" class="theme-choice sf-theme-mobile" data-mobile-menu-choice="hamburger" aria-pressed="false"><span aria-hidden="true">☰</span><span><strong>Hamburguesa</strong><small>Menú desplegable arriba a la derecha</small></span><span class="theme-check" aria-hidden="true">✓</span></button>
<p class="theme-menu__title">Colores</p>
        <button type="button" class="theme-choice" data-theme-choice="dark-teal" aria-pressed="<?= $sfTheme === 'dark-teal' ? 'true' : 'false' ?>"><span class="theme-swatch swatch-dark-teal"></span><span><strong>Esmeralda nocturno</strong><small>Oscuro · Verde</small></span><span class="theme-check" aria-hidden="true">✓</span></button>
        <button type="button" class="theme-choice" data-theme-choice="dark-blue" aria-pressed="<?= $sfTheme === 'dark-blue' ? 'true' : 'false' ?>"><span class="theme-swatch swatch-dark-blue"></span><span><strong>Azul medianoche</strong><small>Oscuro · Azul</small></span><span class="theme-check" aria-hidden="true">✓</span></button>
        <button type="button" class="theme-choice" data-theme-choice="dark-purple" aria-pressed="<?= $sfTheme === 'dark-purple' ? 'true' : 'false' ?>"><span class="theme-swatch swatch-dark-purple"></span><span><strong>Violeta profundo</strong><small>Oscuro · Violeta</small></span><span class="theme-check" aria-hidden="true">✓</span></button>
        <button type="button" class="theme-choice" data-theme-choice="light" aria-pressed="<?= $sfTheme === 'light' ? 'true' : 'false' ?>"><span class="theme-swatch swatch-light"></span><span><strong>Claro limpio</strong><small>Claro · Gris</small></span><span class="theme-check" aria-hidden="true">✓</span></button>
        <button type="button" class="theme-choice" data-theme-choice="light-sand" aria-pressed="<?= $sfTheme === 'light-sand' ? 'true' : 'false' ?>"><span class="theme-swatch swatch-light-sand"></span><span><strong>Marfil cálido</strong><small>Claro · Arena</small></span><span class="theme-check" aria-hidden="true">✓</span></button>
        <button type="button" class="theme-choice" data-theme-choice="solarized" aria-pressed="<?= $sfTheme === 'solarized' ? 'true' : 'false' ?>"><span class="theme-swatch swatch-solarized"></span><span><strong>Solarized</strong><small>Oscuro · Azul petróleo y ámbar</small></span><span class="theme-check" aria-hidden="true">✓</span></button>
        <button type="button" class="theme-choice" data-theme-choice="monokai" aria-pressed="<?= $sfTheme === 'monokai' ? 'true' : 'false' ?>"><span class="theme-swatch swatch-monokai"></span><span><strong>Monokai</strong><small>Oscuro · Grafito y lima</small></span><span class="theme-check" aria-hidden="true">✓</span></button>
        <button type="button" class="theme-choice" data-theme-choice="deep-black" aria-pressed="<?= $sfTheme === 'deep-black' ? 'true' : 'false' ?>"><span class="theme-swatch swatch-deep-black"></span><span><strong>Deep Black</strong><small>Negro profundo · Alto contraste</small></span><span class="theme-check" aria-hidden="true">✓</span></button>
        <button type="button" class="theme-choice" data-theme-choice="ruby-blue" aria-pressed="<?= $sfTheme === 'ruby-blue' ? 'true' : 'false' ?>"><span class="theme-swatch swatch-ruby-blue"></span><span><strong>Ruby Blue</strong><small>Oscuro · Azul y rubí</small></span><span class="theme-check" aria-hidden="true">✓</span></button>
        <p class="theme-menu__note">Los colores se guardan en tu cuenta. La distribución y el menú móvil se guardan en este navegador para tu cuenta.</p>
      </div>
    </details>
    <div class="nav-spacer"></div>
    <div class="nav-brand-note"><span class="status-dot"></span> Audio adaptativo <small>AAC · HLS</small></div>
  </nav>

  <main class="lab-main">
    <?php if (in_array($view,['favorites','recent','playlists'],true)): require __DIR__.'/personal_views.php'; ?>
    <?php elseif ($view === 'offline'): require __DIR__.'/offline_view.php'; ?>
    <?php elseif ($view === 'artists'): ?>
      <section class="card browse-page">
        <div class="browse-heading"><div><p class="eyebrow">EXPLORAR</p><h2>Artistas</h2><p class="muted"><?= count($artists) ?> artista(s) en tu biblioteca</p></div></div>
        <form class="library-search" method="get" action="index.php" role="search">
          <input type="hidden" name="view" value="artists">
          <label class="sr-only" for="artistsSearch">Buscar artista</label>
          <input id="artistsSearch" type="search" name="q" value="<?= lab_h($searchTerm) ?>" placeholder="Buscar artista…" maxlength="120" autocomplete="off">
          <button class="btn primary" type="submit">Buscar</button>
          <?php if ($searchTerm !== ''): ?><a class="btn" href="index.php?view=artists">Limpiar</a><?php endif; ?>
        </form>
        <?php if (!$artists): ?><p class="empty-search">No encontramos artistas con ese nombre.</p><?php else: ?>
          <div class="artist-grid">
            <?php require_once __DIR__.'/lib/cover_url.php'; foreach ($artists as $artist): $firstAlbum = $artist['albums'][0] ?? null; $cover = sf_cover_url($firstAlbum); ?>
              <a class="artist-card" href="index.php?view=library&amp;q=<?= rawurlencode($artist['name']) ?>">
                <span class="artist-avatar" <?= $cover ? 'style="background-image:url(' . lab_h($cover) . ')"' : '' ?>><?php if (!$cover): ?><?= lab_h(function_exists('mb_substr') ? mb_strtoupper(mb_substr($artist['name'], 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($artist['name'], 0, 1))) ?><?php endif; ?></span>
                <span class="artist-card-copy"><strong><?= lab_h($artist['name']) ?></strong><small><?= count($artist['albums']) ?> álbum(es) · <?= (int)$artist['tracks'] ?> pista(s)</small></span>
                <span class="artist-card-arrow" aria-hidden="true">→</span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php elseif ($view === 'tracks'): ?>
      <section class="card browse-page">
        <div class="browse-heading"><div><p class="eyebrow">EXPLORAR</p><h2>Canciones</h2><?php sf_page_size(); ?><p class="muted"><?= $trackPage['total'] ?> pista(s)<?= $searchTerm !== '' ? ' coincidente(s)' : ' en la biblioteca' ?></p></div></div>
        <form class="library-search" method="get" action="index.php" role="search">
          <input type="hidden" name="view" value="tracks"><input type="hidden" name="per_page" value="<?= $trackPage['size'] ?>">
          <label class="sr-only" for="tracksSearch">Buscar canciones</label>
          <input id="tracksSearch" type="search" name="q" value="<?= lab_h($searchTerm) ?>" placeholder="Buscar melodía, artista o álbum…" maxlength="120" autocomplete="off">
          <button class="btn primary" type="submit">Buscar</button>
          <?php if ($searchTerm !== ''): ?><a class="btn" href="index.php?view=tracks">Limpiar</a><?php endif; ?>
        </form>
        <?php if (!$libraryTracks): ?><p class="empty-search">No hay pistas que mostrar.</p><?php else: ?>
          <div class="track-table-wrap"><table class="library-track-table"><thead><tr><th>#</th><th>Melodía</th><th>Álbum</th><th>Artista</th><th class="track-table-action">Acción</th></tr></thead><tbody>
          <?php foreach ($trackPage['items'] as $pageIndex => $row): $a = $row['album']; $t = $row['track']; $name = (string)($t['name'] ?? $t['title'] ?? 'Pista sin título'); ?>
            <tr><td class="track-number"><?= ($trackPage['page'] - 1) * $trackPage['size'] + $pageIndex + 1 ?></td><td><strong><?= lab_h($name) ?></strong><?php if (!empty($t['original'])): ?><small><?= lab_h((string)$t['original']) ?></small><?php endif; ?></td><td><?= lab_h((string)($a['title'] ?? 'Álbum')) ?></td><td><?= lab_h((string)($a['artist'] ?? 'Artista desconocido')) ?></td><td class="track-table-action"><div class="sf-song-actions"><a class="btn primary" href="player.php?album=<?= lab_h(urlencode((string)$a['id'])) ?>&amp;i=<?= (int)$row['index'] ?>" aria-label="Reproducir <?= lab_h($name) ?>">▶ Reproducir</a><span data-sf-track="<?= lab_h((string)$t['id']) ?>"></span><?php if($canManageAlbums): ?><button type="button" class="btn" data-reuse-track="<?= lab_h((string)$t['id']) ?>" data-reuse-source="<?= lab_h($t['_ref_source']??$a['id']) ?>" aria-haspopup="dialog">Álbumes ▾</button><?php endif; ?><?php sf_track_menu($t,(string)$a['id'],true); ?></div></td></tr>
          <?php endforeach; ?>
          </tbody></table></div><?php sf_pager($trackPage,"Canciones"); ?>
        <?php endif; ?>
      </section>
    <?php elseif ($view === 'library'): ?>
      <section class="card">
        <div class="sf-library-heading"><h2>Biblioteca musical</h2><?php sf_page_size(); ?></div>
        <p class="muted"><?= count($albums) ?> álbum(es) · <?= (int) $albumTrackCount ?> pista(s) en álbumes<?php if ($allTracks): ?> · <?= $loosePage['total'] ?> suelta(s)<?php endif; ?></p>

<?php if($searchTerm!==''): ?><a class="btn sf-compact" href="index.php?view=library">Limpiar búsqueda</a><?php endif; ?>

        <?php if ($searchTerm !== ''): ?>
          <p class="muted search-summary">Resultados para <strong><?= lab_h($searchTerm) ?></strong>: <?= $albumPage['total'] ?> álbum(es) coincidente(s), <?= $trackPage['total'] ?> pista(s) coincidente(s).</p>
          <?php if ($matchingAlbums): ?>
            <h3 class="subh">Álbumes coincidentes</h3>
            <div class="album-grid"><?php sf_home_cards(array_map(static fn($a)=>['album'=>$a],$albumPage['items'])); ?></div>
          <?php endif; ?>
          <?php if ($matchingTracks): ?>
            <h3 class="subh">Melodías</h3>
            <ul class="job-list track-list search-track-list">
              <?php foreach ($trackPage['items'] as $result): $a = $result['album']; $t = $result['track']; ?>
                <li>
                  <div class="job-meta"><strong><?= lab_h((string)($t['name'] ?? $t['title'] ?? 'Pista sin título')) ?></strong><span class="muted"><?= lab_h((string)($a['title'] ?? 'Álbum')) ?><?= !empty($a['artist']) ? ' · ' . lab_h((string)$a['artist']) : '' ?></span></div>
                  <div class="job-actions"><a class="btn primary" href="player.php?album=<?= lab_h(urlencode((string)$a['id'])) ?>&amp;i=<?= (int)$result['index'] ?>">▶ Reproducir</a><span data-sf-track="<?= lab_h((string)$t['id']) ?>"></span><?php if($canManageAlbums): ?><button type="button" class="btn" data-reuse-track="<?= lab_h((string)$t['id']) ?>" data-reuse-source="<?= lab_h($t['_ref_source']??$a['id']) ?>" aria-haspopup="dialog">Álbumes ▾</button><?php endif; ?><?php sf_track_menu($t,(string)$a['id'],true); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if (!$matchingAlbums && !$matchingTracks): ?><p class="empty-search">No encontramos melodías ni álbumes que coincidan. Prueba con otro título o artista.</p><?php endif; ?>
        <?php else: ?>
        <h3 class="subh" id="albums">Álbumes</h3>
        <?php if (!$albums): ?>
          <?php if ($canManageAlbums): ?><p class="muted">No hay álbumes. <a href="album_edit.php">Crea el primero</a> y sube los archivos de audio.</p><?php else: ?><p class="muted">Todavía no hay álbumes.</p><?php endif; ?>
        <?php else: ?>
          <div class="album-grid"><?php sf_home_cards(array_map(static fn($a)=>['album'=>$a],$albumPage['items'])); ?></div>
        <?php endif; ?>

        <?php endif; // end search results / full library ?>
<?php sf_pager($albumPage,"Álbumes"); if($searchTerm!=='')sf_pager($trackPage,"Melodías"); ?>
<?php require __DIR__.'/library_tracks.php'; ?>
      </section>
    <?php else: ?>


      <?php require_once __DIR__.'/lib/charts_view.php';sfc_home(); ?>
      <section class="card">
        <h2>Añadidos recientemente</h2><h3 class="subh">Colecciones</h3>
        <article class="sf-home-favorites"><span class="sf-favorites-emblem" aria-hidden="true">★</span><div><strong>Mis favoritos</strong><p class="muted">Tu colección personal de canciones favoritas</p></div><details class="sf-track-options sf-collection-queue" data-queue-only hidden><summary aria-label="Opciones de favoritos">⋮</summary><div class="sf-track-options-panel"><button type="button" data-queue-add="favorites" data-queue-id="1" data-queue-tracks="<?php require_once __DIR__.'/lib/personal_library.php'; echo lab_h(json_encode(array_column(sf_personal_tracks(lab_auth_pdo(),(string)lab_auth_current_account()['id'],'favorites'),'id'))); ?>" hidden>Añadir a cola</button></div></details><a class="btn primary" href="player.php?favorites=1">▶ Reproducir favoritos</a><a class="btn sf-compact" href="index.php?view=favorites">Ver favoritos</a></article>
        <?php if (!$recentItems): ?><p class="muted">Todavía no hay contenido.</p><?php else: ?>
        <div class="album-grid sf-recent-grid" data-home-total="<?= count($recentItems) ?>">
<?php sf_home_cards(array_slice($recentItems,0,4)); ?>
        </div>
        <?php endif; ?>
        <p style="margin-top:1rem"><a class="btn sf-compact" href="index.php?view=library">Ver biblioteca completa →</a></p>
      </section>
      <section class="card sf-home-loose-section">
      <?php
        $isHomeLoose=true;
        $allTracks=array_map(static fn($item)=>$item['track'],$homeLooseItems);
        $loosePage=['items'=>array_slice($allTracks,0,10),'total'=>count($allTracks)];
        require __DIR__.'/library_tracks.php';
      ?>
      </section>
    <?php endif; ?>
  </main>
  </div><!-- /.app-shell -->

<div id="albumCtxMenu" class="ctx-menu" hidden><button type="button" data-queue-add="album" hidden>Añadir a cola</button><?php if ($hasManagementPermission): ?>
  <button type="button" data-act="offline">⤓ Oír sin conexión</button>
  <button type="button" data-act="match">Coincidir álbum…</button>
  <button type="button" data-act="frame">Encuadrar portada…</button>
  <button type="button" data-act="edit">Gestionar pistas</button>
  <?php if ($isAdmin): ?><button type="button" data-act="delete" class="danger">Eliminar álbum…</button><?php else: ?><button type="button" data-act="request-delete" class="danger">Solicitar eliminación…</button><?php endif; ?>
<?php endif; ?></div>

<div id="matchModal" class="modal-overlay" hidden>
  <div class="modal-card" role="dialog" aria-labelledby="matchTitle">
    <div class="modal-head">
      <h3 id="matchTitle">Coincidir álbum</h3>
      <button type="button" class="modal-x" id="matchClose" aria-label="Cerrar">×</button>
    </div>
    <p class="muted" id="matchHint">Elige la portada / álbum correcto</p>
    <div id="matchGrid" class="match-grid"></div>
    <div class="dialog-actions">
      <button type="button" class="btn" id="matchCancel">Cancelar</button>
    </div>
  </div>
</div>

<div id="frameModal" class="modal-overlay" hidden>
  <div class="modal-card" role="dialog">
    <div class="modal-head">
      <h3>Encuadrar portada</h3>
      <button type="button" class="modal-x" id="frameClose" aria-label="Cerrar">×</button>
    </div>
    <div id="framePreview" class="cover-preview"></div>
    <label>Horizontal</label>
    <input type="range" id="frameX" min="0" max="100" value="50">
    <label>Vertical</label>
    <input type="range" id="frameY" min="0" max="100" value="50">
    <label>Zoom</label>
    <input type="range" id="frameZ" min="50" max="200" value="100">
    <div class="dialog-actions">
      <button type="button" class="btn" id="frameCancel">Cancelar</button>
      <button type="button" class="btn primary" id="frameSave">Guardar</button>
    </div>
  </div>
</div>
<script src="assets/themes.js?v=server-theme2" defer></script>
<script src="assets/navigation.js?v=album-return1"></script>
<script src="assets/personal-library.js?v=56"></script>
<script src="assets/loading-skeletons.js?v=1"></script><script src="assets/library.js?v=favalb1"></script>
<script src="assets/library-layout.js?v=3"></script>
<link rel="stylesheet" href="assets/library-layout.css?v=2">
<?php if($hasManagementPermission): ?><script src="assets/track-reuse.js?v=20261116-console1"></script><link rel="stylesheet" href="assets/track-reuse.css?v=2"><?php endif; ?>
<script src="assets/client-processing.js?v=20261114-worker-controls1"></script><script src="assets/loose-live.js?v=2"></script><script src="assets/loose-order.js?v=1"></script><script src="assets/upload-progress.js?v=20261114-import-review1"></script><script src="assets/library-organize.js?v=20261114-import-review1"></script>
<style>.album-card.track-drop-active{outline:3px solid var(--accent,#10b981);outline-offset:4px}li[draggable="true"]{cursor:grab}#loose-upload{flex-wrap:wrap}#loose-upload input{max-width:100%}</style>
<link rel="stylesheet" href="assets/player-icons.css?v=3"><script src="assets/player-icons.js?v=3"></script><script src="assets/shell-player.js?v=20261116-console1"></script>
<script src="assets/player-frame-fit.js?v=14"></script>
<link rel="stylesheet" href="assets/player.css?v=mini-contrast1">
<script src="assets/splash.js" defer></script>
<link rel="stylesheet" href="assets/track-menu.css?v=queue1"><script src="assets/track-menu.js?v=20261113-properties"></script><?php if($hasManagementPermission): ?><script src="assets/audio-jobs.js?v=jobs-panel3"></script><?php endif; ?><script src="assets/usage-heartbeat.js"></script><script src="assets/loose-track-editor.js?v=20261116-console1"></script><?php if($hasManagementPermission): ?><script src="assets/component-health.js?v=auth-poll3"></script><?php endif; ?><?php if($hasManagementPermission): ?><script src="assets/match-loose-artwork.js?v=1"></script><?php endif; ?><?php if($hasManagementPermission): ?><script src="assets/replacement-notices.js?v=theme-sweep1"></script><link rel="stylesheet" href="assets/youtube-source-picker.css?v=2"><script src="assets/youtube-source-picker.js?v=local-upload3"></script><script src="assets/cpu-fill.js?v=2"></script><script src="assets/track-replace.js?v=20261114-import-review1"></script><?php endif; ?><link rel="stylesheet" href="assets/welcome-tips.css?v=1"><script src="assets/welcome-tips.js?v=1" defer></script><?php if((lab_auth_current_account()['role']??'')==='admin'): ?><script src="assets/external-maintenance-notices.js?v=theme-sweep1"></script><?php endif; ?><?php if((lab_auth_current_account()['role']??'')==='admin'): ?><script src="assets/mornye-notices.js?v=auth-poll3"></script><?php endif; ?><script src="assets/mornye-picker.js?v=20261113-transfer"></script><link rel="stylesheet" href="assets/queue-actions.css?v=1"><script src="assets/queue-actions.js?v=membership1"></script><script src="assets/music-charts.js?v=20261115-genres1"></script><link rel="stylesheet" href="assets/communication.css?v=1"><script src="assets/communication.js?v=save-feedback1" defer></script><link rel="stylesheet" href="assets/chart-actions.css?v=manage-menu1"><script src="assets/chart-actions.js?v=1"></script></body>
</html>


