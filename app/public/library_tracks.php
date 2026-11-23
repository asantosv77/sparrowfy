<?php if(!isset($canManageAlbums,$allTracks,$albums,$cfg)){http_response_code(403);exit;} ?>
<?php require_once __DIR__.'/lib/loose_order.php'; ?>
<h3 class="subh" id="loose-tracks">Pistas sin asignar a colección <small data-loose-total="<?= (int)$loosePage['total'] ?>">(<?= $loosePage['total'] ?>)</small><details class="sf-track-options sf-collection-queue" data-queue-only hidden><summary aria-label="Opciones de pistas sin asignar">⋮</summary><div class="sf-track-options-panel"><button type="button" data-queue-add="album" data-queue-id="fffffffffffffffe" data-queue-tracks="<?= lab_h(json_encode(array_column($allTracks,'id'))) ?>" hidden>Añadir a cola</button></div></details></h3>
<?php if(empty($isHomeLoose)): ?>
<form method="get" action="index.php#loose-tracks" class="sf-loose-sort" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:12px 0">
<input type="hidden" name="view" value="library"><input type="hidden" name="q" value="<?=lab_h((string)($_GET['q']??''))?>"><input type="hidden" name="per_page" value="<?= (int)($loosePage['size']??25) ?>"><input type="hidden" name="loose_page" value="1">
<label>Ordenar pistas <select name="loose_sort" aria-label="Ordenar pistas sin colección" style="padding:6px 10px;border-radius:8px;border:1px solid var(--border,#315563);background:var(--panel,#142d37);color:var(--text,#eff7f7)"><?php foreach(sf_loose_order_options() as $value=>$label): ?><option value="<?=lab_h($value)?>" <?=sf_loose_order()===$value?'selected':''?>><?=lab_h($label)?></option><?php endforeach; ?></select></label><button type="submit" class="btn sf-compact">Ordenar</button>
</form>
<?php endif; ?>
<?php if($canManageAlbums): ?>
<?php if(empty($isHomeLoose)): ?><p><button type="button" class="btn" data-match-loose-artwork>Coincidir portadas</button></p><p id="match-loose-artwork-status" role="status" aria-live="polite"></p><?php endif; ?>
<div id="library-organize" data-csrf="<?= lab_h(lab_auth_csrf_token()) ?>">
 <?php if(empty($isHomeLoose)): ?><form id="loose-upload" class="library-search" enctype="multipart/form-data">
 <label>Agregar pistas sin asignar a colección <input name="audio" type="file" accept="<?= lab_h(implode(',',array_map(fn($e)=>'.'.$e,lab_allowed_audio_extensions($cfg)))) ?>" multiple required></label>
 <button type="submit" class="btn primary">Subir a la biblioteca</button><button type="button" class="btn" data-add-youtube>Buscar que añadir</button>
 <?php $refreshParams=array_intersect_key($_GET,array_flip(['q','albums_page','tracks_page','loose_sort']));$refreshParams['view']='library';$refreshParams['per_page']=(int)($loosePage['size']??25);$refreshParams['loose_page']=(int)($loosePage['page']??1); ?>
 <a class="btn" href="<?=lab_h('index.php?'.http_build_query($refreshParams).'#loose-tracks')?>">Actualizar pistas</a>
 </form><?php endif; ?><p id="library-organize-status" role="status" aria-live="polite"></p>
</div>
<?php endif; ?>
<?php if(!$allTracks): ?><p class="muted" data-loose-empty>Todavía no hay pistas sin asignar a colección. Puedes subir archivos sin crear una colección.</p><?php else: ?>
<?php if($canManageAlbums): ?><p class="muted">Arrastra una pista sobre una colección o selecciona su destino y pulsa Mover.</p><?php endif; ?>
<p class="sf-loose-mobile-hint muted">Toca el título para verlo completo; dos toques para reproducir.</p>
<ul class="job-list track-list loose-track-list" data-loose-sort="<?=empty($isHomeLoose)?lab_h(sf_loose_order()):'recent'?>">
<?php $looseRows=$loosePage['items'];require __DIR__.'/loose_track_rows.php'; ?></ul><?php endif; ?>
<?php if(!$allTracks): ?><ul class="job-list track-list loose-track-list" data-loose-sort="<?=empty($isHomeLoose)?lab_h(sf_loose_order()):'recent'?>"></ul><?php endif; ?>

<?php if($allTracks): if(empty($isHomeLoose)): sf_pager($loosePage,"Pistas sin asignar a colección"); else: ?><p><a class="btn sf-compact" href="index.php?view=library#loose-tracks">Ver todas las pistas sin asignar →</a></p><?php endif; endif; ?>



