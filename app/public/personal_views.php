<?php
if(!isset($view)){http_response_code(403);exit;}
require_once __DIR__.'/lib/personal_library.php';$personalUser=lab_auth_current_account();$personalDb=lab_auth_pdo();$personalUid=(string)($personalUser['id']??'');$personalRows=[];$personalList=null;$personalError='';$personalPage=null;$personalAlbums=[];$personalAlbumsPage=null;
require_once __DIR__.'/lib/personal_page.php';
try{
 if($view==='favorites'||$view==='recent'){$personalPage=sf_personal_page($personalDb,$personalUid,$view);$personalRows=$personalPage['items'];}
 if($view==='favorites'){$personalAlbums=sf_fav_albums_load($personalDb,$personalUid);$personalAlbumsPage=sf_page($personalAlbums,'albums_page');}
 if($view==='playlists'&&!empty($_GET['list'])){$personalList=sf_personal_access($personalDb,$personalUid,(string)$_GET['list']);$personalRows=sf_personal_playlist_rows($personalDb,$personalList['id']);}
}catch(Throwable $e){$personalError=$e->getMessage();}
if($personalPage===null&&in_array($view,['favorites','recent'],true))$personalPage=sf_page([],'tracks_page');
?>
<section class="card sf-personal-page" data-personal-view="<?= lab_h($view) ?>">
<div class="sf-library-heading"><h2><?= ['favorites'=>'★ Favoritos','recent'=>'◷ Reproducciones recientes','playlists'=>'☷ Mis listas de reproducción'][$view] ?></h2><?php if($personalPage)sf_page_size(); ?></div>
<p class="muted"><?= $view==='recent'?'Tus últimas 100 canciones reproducidas.':($view==='favorites'?'Tu selección personal, visible solo para tu cuenta.':'Comparte por nombre de usuario o mediante un enlace para añadir una copia.') ?></p>
<?php if($view==='favorites'): ?><details class="sf-track-options sf-collection-queue" data-queue-only hidden><summary aria-label="Opciones de favoritos">⋮</summary><div class="sf-track-options-panel"><button type="button" data-queue-add="favorites" data-queue-id="1" data-queue-tracks="<?= lab_h(json_encode(array_column(sf_personal_tracks($personalDb,$personalUid,'favorites'),'id'))) ?>" hidden>Añadir a cola</button></div></details><?php endif; ?>
<p id="sf-personal-status" role="status" aria-live="polite"><?= lab_h($personalError) ?></p>
<?php if($view==='favorites'): ?>
<h3 class="sf-fav-heading">Colecciones favoritas</h3>
<?php if(!$personalAlbums): ?><p class="muted" id="sf-fav-albums-empty">Todavía no has añadido colecciones. Usa la estrella ★ sobre la portada de una colección en la biblioteca: se guarda la colección completa como atajo, sin copiar sus pistas.</p>
<?php else: ?>
<div class="album-grid sf-favorite-albums"><?php sf_home_cards(array_map(static fn(array $a):array=>['album'=>$a],$personalAlbumsPage['items'])); ?></div>
<?php if($personalAlbumsPage['pages']>1)sf_pager($personalAlbumsPage,'Colecciones favoritas'); ?>
<?php endif; ?>
<h3 class="sf-fav-heading">Canciones favoritas</h3>
<?php endif; ?>
<?php if($view==='playlists'): ?>
<form data-personal-action="create_playlist" class="library-search"><label>Nombre de la nueva lista <input name="name" maxlength="120" required placeholder="Por ejemplo: Para el camino"></label><button class="btn primary" type="submit">Crear lista</button></form>
<?php if(!empty($_GET['share'])): try{$shared=sf_personal_shared($personalDb,(string)$_GET['share']); ?>
<div class="sf-shared-card"><h3><?= lab_h($shared['name']) ?></h3><p>Compartida por <?= lab_h($shared['owner_username']) ?> · <?= count($shared['tracks']) ?> pistas</p><p class="muted">Añadir crea una copia en tu cuenta. Puedes modificarla sin cambiar la original.</p>
<button class="btn primary" data-personal-action="import_playlist" data-token="<?= lab_h((string)$_GET['share']) ?>">Añadir a mis listas</button>
<ol><?php foreach($shared['tracks'] as $st): ?><li><?= lab_h($st['name']) ?></li><?php endforeach; ?></ol></div>
<?php }catch(Throwable $e){ ?><p role="alert"><?= lab_h($e->getMessage()) ?></p><?php }endif; ?>
<p class="muted">Compartir por usuario permite escuchar la lista y recibir sus actualizaciones.</p>
<div class="sf-playlist-grid">
<?php foreach(sf_personal_rows($personalDb,'SELECT p.id,p.name,(SELECT count(*) FROM playlist_tracks pt WHERE pt.playlist_id=p.id) AS track_count FROM playlists p WHERE p.owner_id=? ORDER BY updated_at DESC',[$personalUid]) as $pl): ?>
<article class="sf-playlist-card"><h3><a href="index.php?view=playlists&amp;list=<?= lab_h($pl['id']) ?>"><?= lab_h($pl['name']) ?></a></h3><p><?= (int)$pl['track_count'] ?> pistas</p>
<a class="btn primary" href="player.php?playlist=<?= lab_h($pl['id']) ?>">▶ Reproducir lista</a><button type="button" class="btn" data-queue-add="playlist" data-queue-id="<?= lab_h($pl['id']) ?>" data-queue-tracks="<?= lab_h(json_encode(array_column(sf_personal_playlist_rows($personalDb,$pl['id']),'id'))) ?>" data-queue-minimized hidden>Añadir a cola</button>
<button type="button" class="btn" data-offline-playlist="<?= lab_h($pl['id']) ?>" data-playlist-name="<?= lab_h($pl['name']) ?>" title="Descarga estas canciones en este navegador para escucharlas sin conexión">⤓ Oír sin conexión</button>
<button type="button" class="btn" data-personal-action="rename_playlist" data-playlist-id="<?= lab_h($pl['id']) ?>" data-playlist-name="<?= lab_h($pl['name']) ?>">Renombrar</button>
<button class="btn" data-personal-action="share_playlist" data-playlist-id="<?= lab_h($pl['id']) ?>">Crear / renovar enlace</button>
<button class="btn" data-personal-action="revoke_link" data-playlist-id="<?= lab_h($pl['id']) ?>">Revocar enlace</button>
<form data-personal-action="share_user" class="sf-share-user-form">
 <input type="hidden" name="playlist_id" value="<?= lab_h($pl['id']) ?>">
 <label><span class="sr-only">Compartir por usuario de Sparrow-Fy</span><input name="username" required maxlength="160" autocomplete="off" placeholder="Nombre de usuario"></label>
 <button class="btn primary" type="submit" name="shared" value="1">Compartir con usuario</button>
</form>

<?php $recipients=sf_personal_rows($personalDb,'SELECT u.username FROM playlist_shares ps JOIN users u ON u.id=ps.shared_with_user_id WHERE ps.playlist_id=? ORDER BY u.username',[$pl['id']]);foreach($recipients as $recipient): ?>
<form data-personal-action="share_user" class="sf-recipient"><input type="hidden" name="playlist_id" value="<?= lab_h($pl['id']) ?>"><input type="hidden" name="username" value="<?= lab_h($recipient['username']) ?>"><span>Compartida con <?= lab_h($recipient['username']) ?></span><button class="btn" type="submit" name="shared" value="0">Retirar acceso</button></form>
<?php endforeach; ?></article>
<?php endforeach; ?></div>
<?php $sharedLists=sf_personal_rows($personalDb,'SELECT p.id,p.name,u.username AS owner_username,(SELECT count(*) FROM playlist_tracks pt WHERE pt.playlist_id=p.id) AS track_count FROM playlists p JOIN users u ON u.id=p.owner_id WHERE p.owner_id<>? AND (p.is_shared=1 OR EXISTS(SELECT 1 FROM playlist_shares ps WHERE ps.playlist_id=p.id AND ps.shared_with_user_id=?)) ORDER BY p.updated_at DESC',[$personalUid,$personalUid]); ?>
<h3>Compartidas conmigo</h3>
<?php if(!$sharedLists): ?><p class="muted">Aún no han compartido listas con tu cuenta.</p><?php else: ?><div class="sf-playlist-grid">
<?php foreach($sharedLists as $sp): ?><article class="sf-playlist-card"><h3><?= lab_h($sp['name']) ?></h3><p>De <?= lab_h($sp['owner_username']) ?> · <?= (int)$sp['track_count'] ?> pistas · Solo lectura</p><a class="btn" href="index.php?view=playlists&amp;list=<?= lab_h($sp['id']) ?>">Ver canciones</a><a class="btn primary" href="player.php?playlist=<?= lab_h($sp['id']) ?>">▶ Reproducir lista</a><button type="button" class="btn" data-queue-add="playlist" data-queue-id="<?= lab_h($sp['id']) ?>" data-queue-tracks="<?= lab_h(json_encode(array_column(sf_personal_playlist_rows($personalDb,$sp['id']),'id'))) ?>" data-queue-minimized hidden>Añadir a cola</button></article><?php endforeach; ?></div><?php endif; ?>
<p class="muted">Crear un enlace nuevo invalida el anterior. Revocarlo no borra las copias que otros usuarios ya añadieron.</p>
<div id="sf-share-output" hidden><label>Enlace para compartir <input id="sf-share-url" readonly></label><button class="btn" type="button" id="sf-copy-link">Copiar enlace</button></div>
<?php endif; ?>
<?php if($personalList): ?><button type="button" class="btn" data-queue-add="playlist" data-queue-id="<?= lab_h($personalList['id']) ?>" data-queue-tracks="<?= lab_h(json_encode(array_column(sf_personal_playlist_rows($personalDb,$personalList['id']),'id'))) ?>" data-queue-minimized hidden>Añadir a cola</button><h3><?= lab_h($personalList['name']) ?></h3><?php if((string)$personalList['owner_id']!==$personalUid): ?><p class="muted">De <?= lab_h($personalList['owner_username']) ?> · Acceso de solo lectura</p><?php endif; ?><button type="button" class="btn" data-offline-playlist="<?= lab_h($personalList['id']) ?>" data-playlist-name="<?= lab_h($personalList['name']) ?>">⤓ Oír sin conexión</button><?php endif; ?>
<?php if($view!=='playlists'||$personalList): ?>
<?php if(!$personalRows): ?><p class="muted">Todavía no hay pistas aquí. Usa la estrella o «Añadir a lista» junto a una canción.</p><?php else: ?>
<?php if($view==='favorites'): ?><a class="btn primary" href="player.php?favorites=1">▶ Reproducir favoritos</a><?php endif; ?>
<ul class="job-list track-list sf-compact-tracks">
<?php foreach($personalRows as $pt): ?><li><div class="job-meta"><strong><?= lab_h($pt['name']) ?></strong><span class="muted"><?= lab_h($pt['album_title']) ?></span></div><div class="job-actions">
<a class="btn primary" href="player.php?album=<?= lab_h($pt['album_id']) ?>&amp;i=<?= (int)$pt['position'] ?>">▶ Reproducir</a><span data-sf-track="<?= lab_h($pt['id']) ?>"></span><?php sf_track_menu($pt,(string)$pt['album_id'],false); ?>
<?php if($personalList && (string)$personalList['owner_id']===$personalUid): ?><button class="btn" data-personal-action="playlist_remove" data-playlist-id="<?= lab_h($personalList['id']) ?>" data-track-id="<?= lab_h($pt['id']) ?>">Quitar de esta lista</button><?php endif; ?>
</div></li><?php endforeach; ?></ul>
<?php endif; endif; ?>
<?php if($personalPage)sf_pager($personalPage,$view==='favorites'?'Favoritos':'Recientes'); ?>
</section>






