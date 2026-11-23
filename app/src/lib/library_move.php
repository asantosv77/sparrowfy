<?php
declare(strict_types=1);
require_once __DIR__.'/media_log.php';

require_once __DIR__.'/sqlite_catalog_sync.php';
require_once __DIR__.'/delete_safety.php';
function sf_move_track(string $source,string $target,string $trackId):void{
 require_once __DIR__.'/track_reuse.php';$lock=sf_reuse_lock();try{sf_reuse_db();sf_move_track_impl($source,$target,$trackId);}finally{flock($lock,LOCK_UN);fclose($lock);}
}
function sf_move_track_impl(string $source,string $target,string $trackId,?array &$batch=null):void{
 foreach([$source,$target,$trackId] as $id)if(!preg_match('/^[a-f0-9]{16}$/D',$id))throw new RuntimeException('Identificador invalido');
 if($source===$target)throw new RuntimeException('La pista ya pertenece a esa coleccion');
 $ids=[$source,$target];sort($ids);$locks=[];$moved=false;$changed=false;$oldPath='';$newPath='';$db=$batch['db']??lab_auth_pdo();$ownTx=$batch===null;
 try{
 if($ownTx)foreach($ids as $id){$path=LAB_ALBUMS.'/'.$id.'/.processing-append.lock';if(!is_dir(dirname($path)))throw new RuntimeException('Coleccion inexistente');$f=fopen($path,'c');if(!$f||!flock($f,LOCK_EX|LOCK_NB))throw new RuntimeException('Coleccion ocupada. Intenta de nuevo');$locks[]=$f;}
 $a=$batch['albums'][$source]??lab_load_album($source);$b=$batch['albums'][$target]??lab_load_album($target);if(!$a||!$b)throw new RuntimeException('Coleccion inexistente');
 $online=($a['album_type']??'')==='online_links';if($online!== (($b['album_type']??'')==='online_links'))throw new RuntimeException('Las pistas HLS se organizan en colecciones de archivos, no en colecciones de enlaces');
 if($ownTx)$db->beginTransaction();
 $q=$db->prepare('SELECT album_id FROM tracks WHERE id=?');$q->execute([$trackId]);if(!$online && $q->fetchColumn()!==$source)throw new RuntimeException('El catalogo de la pista requiere sincronizacion antes de mover');
 foreach(['sf_processing_jobs','audio_edit_jobs'] as $table){$q=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);if(!$q->fetchColumn())continue;$q=$db->prepare("SELECT 1 FROM $table WHERE album_id IN (?,?) AND state IN ('queued','running') LIMIT 1");$q->execute([$source,$target]);if($q->fetchColumn())throw new RuntimeException('Espera a que terminen los trabajos de estas colecciones');}
 $index=null;foreach($a['tracks']??[] as $i=>$t)if(($t['id']??'')===$trackId)$index=$i;
 if($index===null)throw new RuntimeException('La pista ya no esta en su coleccion original');
 foreach($b['tracks']??[] as $t)if(($t['id']??'')===$trackId)throw new RuntimeException('Pista duplicada en destino');
 $originalA=$a;$originalB=$b;$t=$a['tracks'][$index];$oldPath='';if(!$online){$oldPath=hml_delete_validate_track_dir($source,$t);
 $dest=lab_album_hls_dir($target,(string)($b['title']??''));$newPath=$dest.'/'.basename($oldPath);
 if(file_exists($newPath))throw new RuntimeException('El destino ya contiene archivos con ese identificador');
 sf_media_event('move.started',['album_id'=>$source,'track_id'=>$trackId,'source'=>$oldPath.'/master.m3u8','destination'=>$newPath.'/master.m3u8']);
 if(!$ownTx){$batch['moves'][]=[$oldPath,$newPath];sf_reuse_delete_checkpoint($batch,'moving');}
 if(!rename($oldPath,$newPath))throw new RuntimeException('No se pudieron mover los archivos HLS');$moved=true;
 $t['hls']='hls_out/'.basename($dest).'/'.basename($newPath).'/master.m3u8';}
 array_splice($a['tracks'],(int)$index,1);$b['tracks'][]=$t;
 $changed=true;hml_delete_atomic_save_album($a);hml_delete_atomic_save_album($b);
 lab_sqlite_sync_album($a,$db);lab_sqlite_sync_album($b,$db);
 $db->prepare('DELETE FROM sf_album_track_refs WHERE album_id=? AND track_id=?')->execute([$target,$trackId]);
 $db->prepare('UPDATE sf_album_track_refs SET source_album_id=? WHERE source_album_id=? AND track_id=?')->execute([$target,$source,$trackId]);if($ownTx)$db->commit();else{$batch['albums'][$source]=$a;$batch['albums'][$target]=$b;}
 sf_media_event($ownTx?'move.committed':'move.staged',['album_id'=>$source,'track_id'=>$trackId,'source'=>$oldPath.'/master.m3u8','destination'=>$newPath.'/master.m3u8']);
 }catch(Throwable $e){
 sf_media_event('move.failed',['album_id'=>$source,'track_id'=>$trackId,'source'=>$oldPath.'/master.m3u8','destination'=>$newPath.'/master.m3u8'],$e);
 if(!$ownTx)throw $e;
 if($db->inTransaction())$db->rollBack();$errors=[];
 if($moved&&!rename($newPath,$oldPath))$errors[]='archivos HLS';
 if($changed){foreach([$originalA,$originalB] as $original){try{hml_delete_atomic_save_album($original);}catch(Throwable){$errors[]='catalogo JSON';}}}
 sf_media_event('move.rollback',['album_id'=>$source,'track_id'=>$trackId,'remaining'=>count($errors)]);
 if($errors)throw new RuntimeException('Se requiere revision de recuperacion: '.implode(', ',$errors),0,$e);throw $e;
 }finally{foreach(array_reverse($locks) as $f){flock($f,LOCK_UN);fclose($f);}}
}



