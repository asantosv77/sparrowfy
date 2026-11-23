<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
require_once __DIR__.'/lib/user_preferences.php';
$embed=!empty($_GET['embed']);
require_once __DIR__.'/lib/album_deletion.php';
require_once __DIR__.'/lib/cover_url.php';
lab_auth_require_admin();
$sfTheme = lab_user_theme((string)(lab_auth_current_account()['id'] ?? ''));
$pending = sf_delreq_pending();
$items = [];
foreach ($pending as $row) {
    $album = lab_load_album((string)$row['album_id']);
    if (!$album) continue;
    $items[] = ['request' => $row, 'album' => $album];
}
?><!DOCTYPE html>
<html lang="es" data-theme="<?= lab_h($sfTheme) ?>" data-theme-source="server">
<head>
  <meta charset="utf-8">
  <meta name="sf-csrf" content="<?= lab_h(lab_auth_csrf_token()) ?>">
  <meta name="sf-player-account" content="<?= lab_h((string)(lab_auth_current_account()['id'] ?? '')) ?>">
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <meta name="application-name" content="HLS Music Lab">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Confirmar eliminación de colección · Sparrow-Fy</title>
  <script>try{document.documentElement.dataset.theme=<?= json_encode($sfTheme) ?>}catch(e){}</script>
  <link rel="stylesheet" href="assets/lab.css?v=theme-sweep1">
  <link rel="stylesheet" href="assets/track-menu.css?v=5">
  <style>
    .sf-del-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem}
    .sf-del-card{position:relative;display:flex;flex-direction:column;gap:.55rem;padding:1rem}
    .sf-del-cover{width:100%;aspect-ratio:1/1;border-radius:10px;background-size:cover;background-position:center;background-color:var(--surface-raised,#e5edf2)}
    .sf-del-cover-wrap{position:relative}
    .sf-del-badge{display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem;font-weight:600;padding:.15rem .5rem;border-radius:999px;background:var(--danger-bg,#35151b);color:var(--danger,#fca5a5)}
    .sf-del-actions{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:auto}
    .sf-del-actions form{margin:0}
    .sf-del-requester{font-size:.82rem;line-height:1.35}
    .sf-del-empty{padding:1.25rem 0}
  </style>
<link rel="stylesheet" href="assets/loading-skeletons.css?v=1"><meta name="sf-session-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><script src="assets/session-sync.js?v=auth-poll3" defer></script>
<script defer src=assets/sf-language.js></script></head>
<body>
  <header class="lab-header">
    <div>
      <h1>Confirmar Eliminación de Colección</h1>
      <p class="muted">Solicitudes pendientes de los gestores. Escucha, gestiona o decide si borrar definitivamente.</p>
    </div>
    <div class="header-actions">
      <a class="btn" href="config.php<?= $embed?'?embed=1':'' ?>">← Volver atrás</a>
      <a class="btn" href="index.php?view=library">Biblioteca</a>
    </div>
  </header>
  <main class="lab-main">
    <?php if (!$items): ?>
      <section class="card">
        <h2>Sin solicitudes pendientes</h2>
        <p class="muted sf-del-empty">No hay colecciones esperando confirmación de borrado.</p>
        <a class="btn" href="config.php<?= $embed?'?embed=1':'' ?>">Volver a Configuración</a>
      </section>
    <?php else: ?>
      <section class="card" style="margin-bottom:1rem">
        <p><strong><?= count($items) ?></strong> colección(es) solicitadas para eliminar. Restaurar la colección la devuelve a la biblioteca de todos los gestores.</p>
      </section>
      <div class="sf-del-grid">
        <?php foreach ($items as $item):
          $album = $item['album']; $req = $item['request'];
          $cover = sf_cover_url($album);
          $aid = (string)($album['id'] ?? '');
          $title = (string)($album['title'] ?? 'Sin título');
          $artist = (string)($album['artist'] ?? '');
          $tracks = count($album['tracks'] ?? []);
          $when = (string)($req['requested_at'] ?? '');
          try { $whenLabel = $when !== '' ? (new DateTimeImmutable($when))->format('d/m/Y H:i') : ''; } catch (Throwable $e) { $whenLabel = $when; }
        ?>
        <article class="card sf-del-card album-card" data-album-id="<?= lab_h($aid) ?>" data-album-title="<?= lab_h($title) ?>" data-album-artist="<?= lab_h($artist) ?>">
          <div class="sf-del-cover-wrap">
            <button type="button" class="album-menu-btn" title="Opciones" aria-label="Menú contextual de <?= lab_h($title) ?>">⋮</button>
            <a class="album-cover-link" href="player.php?album=<?= lab_h(rawurlencode($aid)) ?>" aria-label="Reproducir <?= lab_h($title) ?>">
              <div class="sf-del-cover" style="<?= $cover ? 'background-image:url(&quot;' . lab_h($cover) . '&quot;)' : '' ?>"></div>
            </a>
          </div>
          <span class="sf-del-badge">Eliminación solicitada</span>
          <strong><?= lab_h($title) ?></strong>
          <span class="muted"><?= lab_h($artist) ?> · <?= (int)$tracks ?> pista(s)</span>
          <span class="sf-del-requester muted">Solicitada por <strong><?= lab_h((string)($req['requested_name'] ?? 'desconocido')) ?></strong><?= $whenLabel !== '' ? ' · ' . lab_h($whenLabel) : '' ?></span>
          <div class="sf-del-actions">
            <a class="btn" href="player.php?album=<?= lab_h(rawurlencode($aid)) ?>">▶ Reproducir</a>
            <a class="btn" href="album_edit.php?id=<?= lab_h(rawurlencode($aid)) ?>">Gestionar pistas</a>
            <button class="btn" type="button" data-restore-delete="<?= lab_h($aid) ?>" data-album-title="<?= lab_h($title) ?>">Restaurar</button>
            <button class="btn danger" type="button" data-purge-album="<?= lab_h($aid) ?>" data-album-title="<?= lab_h($title) ?>">Eliminar definitivamente</button>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <div id="albumCtxMenu" class="ctx-menu" hidden>
    <button type="button" data-act="offline">⤓ Oír sin conexión</button>
    <button type="button" data-act="match">Coincidir álbum…</button>
    <button type="button" data-act="frame">Encuadrar portada…</button>
    <button type="button" data-act="edit">Gestionar pistas</button>
    <button type="button" data-act="delete" class="danger">Eliminar álbum…</button>
  </div>

  <div id="matchModal" class="modal-overlay" hidden>
    <div class="modal-card" role="dialog" aria-labelledby="matchTitle">
      <div class="modal-head"><h3 id="matchTitle">Coincidir álbum</h3><button type="button" class="modal-x" id="matchClose" aria-label="Cerrar">×</button></div>
      <p class="muted" id="matchHint">Elige la portada / álbum correcto</p>
      <div id="matchGrid" class="match-grid"></div>
      <div class="dialog-actions"><button type="button" class="btn" id="matchCancel">Cancelar</button></div>
    </div>
  </div>

  <div id="frameModal" class="modal-overlay" hidden>
    <div class="modal-card" role="dialog">
      <div class="modal-head"><h3>Encuadrar portada</h3><button type="button" class="modal-x" id="frameClose" aria-label="Cerrar">×</button></div>
      <div id="framePreview" class="cover-preview"></div>
      <label>Horizontal</label><input type="range" id="frameX" min="0" max="100" value="50">
      <label>Vertical</label><input type="range" id="frameY" min="0" max="100" value="50">
      <label>Zoom</label><input type="range" id="frameZ" min="50" max="200" value="100">
      <div class="dialog-actions"><button type="button" class="btn" id="frameCancel">Cancelar</button><button type="button" class="btn primary" id="frameSave">Guardar</button></div>
    </div>
  </div>

  <script src="assets/themes.js?v=server-theme2" defer></script>
  <script src="assets/loading-skeletons.js?v=1"></script><script src="assets/library.js?v=favalb1"></script>
  <script>
  (() => {
    'use strict';
    const csrf = () => document.querySelector('meta[name="sf-csrf"]')?.content || '';
    const notice = (text) => window.sfPlayerNotice ? window.sfPlayerNotice(text) : alert(text);
    async function post(action, albumId) {
      const fd = new FormData();
      fd.set('csrf_token', csrf());
      fd.set('action', action);
      fd.set('album_id', albumId);
      const r = await fetch('api_album_manage.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json().catch(() => ({}));
      if (!r.ok || j.error) throw Error(j.error || 'No se pudo completar la acción');
      return j;
    }
    // Aviso antes de borrar: cuántos usuarios tienen la colección en favoritos.
    async function favoritesNote(albumId) {
      try {
        const j = await post('favorites_preview', albumId);
        const count = Number(j.count) || 0;
        if (!count) return '';
        const users = Array.isArray(j.users) ? j.users.filter(Boolean) : [];
        let text = 'Esta colección está en los favoritos de ' + count + (count === 1 ? ' usuario' : ' usuarios') + '.\n';
        if (users.length) text += 'La tienen: ' + users.join(', ') + (count > users.length ? ' y más' : '') + '.\n';
        text += 'Al eliminarla también desaparecerá de sus favoritos.\n\n';
        return text;
      } catch (_) { return ''; }
    }
    document.addEventListener('click', async (event) => {
      const restore = event.target.closest('[data-restore-delete]');
      if (restore) {
        if (restore.disabled) return;
        if (!confirm('¿Restaurar la colección «' + restore.dataset.albumTitle + '»? Volverá a la biblioteca de los gestores.')) return;
        restore.disabled = true;
        try {
          await post('restore_delete', restore.dataset.restoreDelete);
          restore.closest('.sf-del-card')?.remove();
          notice('Colección restaurada.');
          if (!document.querySelector('.sf-del-card')) location.reload();
        } catch (e) { alert(e.message); restore.disabled = false; }
        return;
      }
      const purge = event.target.closest('[data-purge-album]');
      if (purge) {
        if (purge.disabled) return;
        const title = purge.dataset.albumTitle || 'la colección';
        const favNote = await favoritesNote(purge.dataset.purgeAlbum);
        if (!confirm(favNote + '¿Eliminar definitivamente «' + title + '»?\n\nSe borrarán metadatos y archivos HLS. No se puede deshacer.')) return;
        if (!confirm('Última confirmación: el borrado de «' + title + '» es permanente. ¿Continuar?')) return;
        purge.disabled = true;
        try {
          const j = await post('delete', purge.dataset.purgeAlbum);
          if (j.warning) alert(j.warning);
          purge.closest('.sf-del-card')?.remove();
          notice('Colección eliminada definitivamente.');
          if (!document.querySelector('.sf-del-card')) location.reload();
        } catch (e) { alert(e.message); purge.disabled = false; }
      }
    });
  })();
  </script>
</body>
</html>
