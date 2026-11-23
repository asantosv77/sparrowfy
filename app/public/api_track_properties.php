<?php
require __DIR__.'/lib.php';lab_auth_require_album_manager();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$id=(string)($_GET['id']??'');$track=null;$album=null;
require_once __DIR__.'/lib/track_lookup.php';
$found=sf_track_lookup($id);if($found){$track=$found['track'];$album=$found['album'];}
if(!$track){http_response_code(404);echo json_encode(['error'=>'Pista no encontrada']);exit;}
require_once __DIR__.'/lib/track_storage.php';$storage=null;$disk=[];try{$storage=sf_track_storage_bytes($track);$disk['Almacenamiento total']=sf_track_storage_format($storage['total_bytes']);$disk['Archivo origen conservado']=$storage['source_bytes']?sf_track_storage_format($storage['source_bytes']):'No conservado';foreach($storage['qualities'] as $quality=>$bytes)$disk['Paquete '.$quality]=sf_track_storage_format($bytes);$disk['Manifiestos y auxiliares']=sf_track_storage_format($storage['auxiliary_bytes']);$disk['Medición']=$storage['measurement'];}catch(Throwable $e){$disk['Almacenamiento']='No se pudo medir el audio disponible';}
$t=$track;$seconds=(int)($t['duration']??0);
$origin=[];$originUrl=(string)($t['source_url']??$t['source_mornye_url']??'');if($originUrl!==''){$service=(string)($t['source_service']??(!empty($t['source_mornye_url'])?'mornye':'youtube'));$origin=['Servicio de origen'=>$service==='mornye'?'Mornye':'YouTube','URL de origen'=>$originUrl];}
echo json_encode(['properties'=>['Título'=>$t['title']??$t['name']??'Sin título','Artista'=>$t['artist']??$album['artist']??'No indicado','Archivo original'=>$t['original']??'No disponible','Duración'=>sprintf('%d:%02d',intdiv($seconds,60),$seconds%60),'Calidad de origen'=>!empty($t['source_kbps'])?$t['source_kbps'].' kbps':'No disponible','Calidades disponibles'=>implode(', ',$t['renditions']??[]),'Álbum de metadatos'=>$t['album']??'Sin indicar','Colección de origen'=>$album['id']==='fffffffffffffffe'?'Pistas sin asignar a colección':$album['title'],'Identificador'=>$id]+$origin+$disk,'storage'=>$storage],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
