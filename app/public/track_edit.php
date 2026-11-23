<?php
require __DIR__.'/lib.php';require_once __DIR__.'/lib/user_preferences.php';lab_auth_require_album_manager();
$sfTheme=lab_user_theme((string)(lab_auth_current_account()['id']??''));
require_once __DIR__.'/lib/track_editor.php';
header('Cache-Control: no-store');
$tid=(string)($_GET['track']??'');
if(!preg_match('/^[a-f0-9]{16}$/D',$tid)){http_response_code(400);exit('Pista inválida');}
$q=lab_auth_pdo()->prepare('SELECT album_id FROM tracks WHERE id=?');$q->execute([$tid]);$aid=$q->fetchColumn();$q->closeCursor();
if(!is_string($aid)||!preg_match('/^[a-f0-9]{16}$/D',$aid)){http_response_code(404);exit('Pista no encontrada');}
$album=lab_load_album($aid);$idx=null;foreach($album['tracks']??[] as $i=>$entry)if($entry['id']===$tid)$idx=$i;
if($idx===null){http_response_code(404);exit('Pista no encontrada');}
$error='';$saved=false;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 $lock=fopen(LAB_ALBUMS.'/'.$aid.'/.processing-append.lock','c');
 try{
  if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Colección ocupada');
  $album=lab_load_album($aid);$idx=null;foreach($album['tracks']??[] as $i=>$entry)if($entry['id']===$tid)$idx=$i;
  if($idx===null)throw new RuntimeException('La pista cambió de ubicación. Vuelve a abrir el editor.');
  $next=sf_editor_metadata($_POST,$album['tracks'][$idx]);if(isset($_FILES['cover_image'])&&($_FILES['cover_image']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)$next['artwork']=sf_editor_store_cover($_FILES['cover_image']);$album['tracks'][$idx]=$next;lab_save_album($album);$saved=true;
 }catch(Throwable $e){$error=$e->getMessage();}finally{if($lock){flock($lock,LOCK_UN);fclose($lock);}}
}
$t=$album['tracks'][$idx];$hasAudio=!empty($t['hls'])&&!empty($t['job_id']);
if(($_GET['source']??'')==='1'){header('Cache-Control: no-store');header('Content-Type: application/json');echo json_encode(['source'=>'stream.php?path='.rawurlencode(preg_replace('~^hls_out/~','',(string)($t['hls']??''))),'duration'=>(float)($t['duration']??0)]);exit;}
function sf_edit_field(string $key,string $label,string $help,array $t):void{ ?>
<label class="editor-field field-<?=lab_h($key)?>"><span><?=lab_h($label)?></span><input name="<?=lab_h($key)?>" id="edit-<?=lab_h($key)?>" value="<?=lab_h((string)($t[$key]??''))?>" maxlength="<?=$key==='genre'?100:($key==='year'?4:160)?>" title="<?=lab_h($help)?>" <?=$key==='name'?'required':''?> <?=$key==='year'?'inputmode="numeric" pattern="[0-9]{0,4}"':''?>></label>
<?php }
?><!doctype html><html lang="es" data-theme="<?=lab_h($sfTheme)?>" data-theme-source="server"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="sf-csrf" content="<?=lab_h(lab_auth_csrf_token())?>"><title>Editar melodía · Sparrow-Fy</title><link rel="stylesheet" href="assets/lab.css?v=theme-sweep1"><link rel="stylesheet" href="assets/track-editor.css?v=theme-sweep1"><script src="assets/artwork-backup.js?v=20261116-console1" defer></script><link rel="stylesheet" href="assets/loading-skeletons.css?v=1"><meta name="sf-session-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><script src="assets/session-sync.js?v=auth-poll3" defer></script>
<meta name="sf-save-result" content="<?= $error!==''?'error':($saved?'success':'') ?>"><link rel="stylesheet" href="assets/save-feedback.css?v=2"><script src="assets/save-feedback.js?v=2"></script><script defer src=assets/sf-language.js></script></head><body class="track-editor-body"><main class="track-editor-wrap">
<header class="editor-heading"><div><p class="editor-eyebrow">BIBLIOTECA MUSICAL</p><h1>Editar metadatos</h1><p class="muted">Metadatos, identificación, portada y edición de audio.</p></div><button type="button" id="closeEdit" class="btn" title="Cerrar el editor; las tareas enviadas continúan en el servidor">Cerrar</button></header>
<?php if($error):?><p class="editor-alert" role="alert"><?=lab_h($error)?></p><?php endif;?>
<?php if($saved):?><script>window.parent.postMessage({source:'sf-track-edit',type:'saved'},location.origin);</script><p role="status">Metadatos guardados.</p><?php endif;?>
<form method="post" enctype="multipart/form-data" id="trackMetadataForm"><input type="hidden" name="csrf_token" value="<?=lab_h(lab_auth_csrf_token())?>">
<section class="editor-section"><h2>Información de la melodía <button type="button" class="editor-help" data-editor-help="El nombre visible identifica la pista en las listas. Título, artista y álbum ayudan a encontrar letras y portadas. Guardar estos datos no modifica el audio." aria-label="Ayuda sobre los metadatos">?</button></h2><div class="metadata-fields">
<?php sf_edit_field('name','Nombre visible','Nombre que se muestra en el catálogo y en la cola del reproductor.',$t);
sf_edit_field('title','Título','Título de la canción para buscar letras e identificarla; no requiere repetir el artista.',$t);
sf_edit_field('album','Álbum','Nombre del lanzamiento musical. Este campo no mueve la pista a otra colección.',$t);
sf_edit_field('artist','Artista','Intérprete de la canción; se usa en la búsqueda de letras y portadas.',$t);
sf_edit_field('genre','Género','Clasificación musical opcional.',$t);
sf_edit_field('year','Año','Año del lanzamiento; opcional, hasta cuatro dígitos.',$t); ?></div>
</section>
<div class="editor-columns">

<section class="editor-section editor-identification"><h2>Identificación y portada <button type="button" class="editor-help" data-editor-help="AcoustID analiza el contenido musical y propone metadatos. Buscar portadas usa título, artista y álbum; los cambios se aplican al pulsar Guardar metadatos." aria-label="Ayuda sobre identificación y portada">?</button></h2>
<div class="editor-acoustid"><button type="button" class="btn" id="identifyAudio" data-track="<?=lab_h($tid)?>" title="Analizar la huella acústica y proponer título, artista y álbum" <?=$hasAudio?'':'disabled'?>>Identificar con AcoustID</button><p class="editor-note">Puedes escuchar la pista antes de elegir una coincidencia.</p><p id="identifyStatus" role="status"></p><div id="identifyResults"></div></div>
<div class="editor-cover-row"><div class="editor-artwork"><img id="preview" alt="Portada seleccionada" <?=empty($t['artwork'])?'hidden':'src="'.lab_h($t['artwork']).'"'?>><span aria-hidden="true">♫</span></div><div class="editor-cover-actions"><button type="button" class="btn" id="findCover" title="Buscar portadas que coincidan con el título, artista y álbum del formulario">Buscar portada</button><button type="button" class="btn" id="chooseCoverFile" title="Elegir JPG, PNG, WebP o GIF desde tu equipo; se aplica al guardar metadatos">Elegir imagen</button><input type="file" name="cover_image" id="coverImageFile" accept="image/jpeg,image/png,image/webp,image/gif" hidden><button type="button" class="btn" id="clearCover" title="Quitar la portada de esta pista al guardar los cambios">Quitar portada</button></div></div>
<div id="coverPreparation" hidden><p id="coverPreparationStatus" role="status" aria-live="polite"></p><div id="coverPreparationSkeleton" aria-hidden="true"></div></div>
<p id="coverFileStatus" class="editor-note" role="status" aria-live="polite">JPG, PNG, WebP o GIF · máximo 8 MiB. Al guardar: WebP de hasta 600 × 600 px, sin ampliar.</p><input type="hidden" name="artwork" id="artwork" value="<?=lab_h((string)($t['artwork']??''))?>">
</section>
<section class="editor-section editor-audio"><h2>Recorte y fundidos <button type="button" class="editor-help" data-editor-help="Los recortes indican cuántos segundos quitar, no la posición final. Los fundidos suavizan el volumen del inicio o final del audio resultante. Procesar audio crea un trabajo independiente de Guardar metadatos." aria-label="Ayuda sobre recorte y fundidos">?</button></h2>
<p class="editor-note">Duración actual: <strong><?=lab_h(sprintf('%d:%02d.%02d',intdiv((int)round((float)($t['duration']??0)*100),6000),intdiv((int)round((float)($t['duration']??0)*100),100)%60,(int)round((float)($t['duration']??0)*100)%100))?></strong> · Valores en segundos. <strong>0</strong> conserva ese extremo.</p>
<div class="editor-audio-fields">
<?php foreach(['trim_start'=>['Recortar inicio','Segundos que se eliminan del principio de la pista.'],'trim_end'=>['Recortar final','Segundos que se eliminan del final; no es la posición en la que termina.'],'fade_in'=>['Fade in','Segundos durante los que sube progresivamente el volumen al comenzar.'],'fade_out'=>['Fade out','Segundos durante los que baja progresivamente el volumen al terminar.']] as $key=>$field): ?>
<label class="editor-field"><span><?=lab_h($field[0])?></span><input type="number" id="<?=$key?>" name="<?=$key?>" min="0" max="10800" step="0.01" value="0" title="<?=lab_h($field[1])?>" <?=$hasAudio?'':'disabled'?>></label>
<?php endforeach; ?></div>
<p id="audioDurationEstimate" class="editor-note" aria-live="polite"></p>
<button type="button" class="btn" id="queueTrackAudioEdit" data-album="<?=lab_h($aid)?>" data-track="<?=lab_h($tid)?>" data-duration="<?=(float)($t['duration']??0)?>" title="Enviar solo el recorte y los fundidos a la cola de procesamiento" <?=$hasAudio?'':'disabled'?>>Procesar recorte / fade</button>
<p class="editor-note">El procesamiento continúa aunque cierres esta ventana. Guardar metadatos es una acción independiente.</p>
<p id="trackAudioEditStatus" role="status"></p><progress id="trackAudioEditProgress" max="100" hidden aria-label="Progreso de edición de audio"></progress>
</section></div>
<footer class="editor-footer"><button class="btn primary" type="submit" title="Guardar nombre, metadatos y portada; no inicia el procesamiento del audio">Guardar metadatos</button><button class="btn" type="button" id="metadataPreviewPlay" title="Reproducir una muestra sin guardar una posición para continuar después" aria-pressed="false" <?=$hasAudio?'':'disabled'?>>▶ Reproducir pista</button><span class="editor-note">Los cambios afectan a las colecciones que reutilizan esta pista.</span></footer>
<div id="metadataSaveFeedback" hidden><p role="status" aria-live="polite">Guardando metadatos y portada…</p><div id="metadataSaveSkeleton" aria-hidden="true"></div></div>
<p id="metadataPreviewStatus" role="status" class="muted"></p><div id="metadataPreviewHost" hidden class="metadata-mini-player" data-source="<?=lab_h('stream.php?path='.rawurlencode(preg_replace('~^hls_out/~','',(string)($t['hls']??''))))?>"><strong><?=lab_h((string)$t['name'])?></strong><span id="metadataPreviewTime" style="font-variant-numeric:tabular-nums;white-space:nowrap">0:00.00 / 0:00.00</span><input type="range" id="metadataPreviewSeek" min="0" max="1" step="0.01" value="0" aria-label="Posición de reproducción"><label>Volumen <input type="range" id="metadataPreviewVolume" min="0" max="1" step="0.05" value="1" aria-label="Volumen de la prueba"></label><audio id="metadataPreviewAudio" preload="none"></audio></div>
</form>
<dialog id="coverPickerDialog" aria-labelledby="coverPickerTitle"><header><h2 id="coverPickerTitle">Elegir portada</h2><button type="button" class="btn" id="closeCoverPicker">Cerrar</button></header><p id="coverStatus" role="status"></p><div id="covers" class="covers"></div></dialog>
</main><script src="assets/loading-skeletons.js?v=1"></script><script src="assets/metadata-save-feedback.js?v=save-feedback1"></script><script src="assets/loose-track-editor.js?v=20261116-console1"></script><script src="assets/hls-1.5.17.min.js"></script><script src="assets/metadata-preview.js?v=centiseconds1"></script><script src="assets/track-editor-audio.js?v=centiseconds1"></script></body></html>
