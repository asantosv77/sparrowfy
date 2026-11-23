<?php
require_once __DIR__.'/media_log.php';
require_once __DIR__.'/output_lifecycle.php';

/** Atomic receipt: unfinished publication must never authorize old-audio retirement. */
function sf_replace_receipt(string $file,array $data): void {
 $tmp=$file.'.'.bin2hex(random_bytes(6)).'.tmp';
 try{
  $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
  if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('No se pudo respaldar la pista');
  chmod($tmp,0660);if(!rename($tmp,$file))throw new RuntimeException('No se pudo publicar el respaldo de pista');
 }finally{if(is_file($tmp))unlink($tmp);}
}

function sf_replace_merge(array $old,array $new,array $tags,string $job):array{
 $merged=$old;
 foreach(['hls','original','job_id','duration','source_kbps','renditions','source_codec','source_url','source_service','source_mornye_url','source_mornye_job'] as $key){if(array_key_exists($key,$new))$merged[$key]=$new[$key];else if(in_array($key,['source_url','source_codec','source_service','source_mornye_url','source_mornye_job'],true))unset($merged[$key]);}
 foreach(['title','artist','album','genre','year'] as $key){$value=trim((string)($tags[$key]??''));if(empty($merged[$key])&&$value!==''&&mb_strlen($value)<=160&&!preg_match('/[\x00-\x1f\x7f]/',$value))$merged[$key]=$value;}
 $merged['id']=$old['id'];$merged['replacement_job_id']=$job;$merged['replaced_at']=gmdate('c');return $merged;
}
/** The album append lock is held by the caller. Never leave JSON ahead of SQLite. */
function sf_replace_catalog_swap(array $album,string $tid,string $expected):void {
 require_once __DIR__.'/sqlite_catalog_sync.php';
 $file=LAB_ALBUMS.'/'.$album['id'].'/album.json';$before=file_get_contents($file);
 if(!is_string($before))throw new RuntimeException('No se pudo respaldar album.json');
 $json=json_encode($album,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 $temp=$file.'.swap-'.bin2hex(random_bytes(8)).'.tmp';$published=false;$db=lab_auth_pdo();
 try{
  if(file_put_contents($temp,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('No se pudo preparar album.json');
  chmod($temp,0660);$db->beginTransaction();
  // Acquire the writer before reading a snapshot; revalidate the source in SQLite.
  $q=$db->prepare('UPDATE tracks SET updated_at=updated_at WHERE id=? AND album_id=? AND hls_path=?');$q->execute([$tid,$album['id'],$expected]);
  if($q->rowCount()!==1)throw new RuntimeException('La pista cambió antes de publicar; no se sustituyó su audio');
  lab_sqlite_sync_album($album,$db);
  if(!rename($temp,$file))throw new RuntimeException('No se pudo publicar album.json');$published=true;
  $db->commit();
 }catch(Throwable $e){
  $errors=[];if($db->inTransaction()){try{$db->rollBack();}catch(Throwable $r){$errors[]='SQLite';}}
  if($published){$restore=$file.'.restore-'.bin2hex(random_bytes(8)).'.tmp';
   if(file_put_contents($restore,$before,LOCK_EX)!==strlen($before)||!@rename($restore,$file)){$errors[]='album.json';@unlink($restore);}
  }
  sf_media_event('publication.rollback',['album_id'=>$album['id'],'track_id'=>$tid,'remaining'=>count($errors)],$e);
  if($errors)throw new RuntimeException('Revisión de recuperación necesaria: '.implode(', ',$errors),0,$e);throw $e;
 }finally{if(is_file($temp))@unlink($temp);}
}
/** Remove only this task's unpublished output, after checking every canonical track. */
function sf_replace_remove_unpublished(array $info):void {
 require_once __DIR__.'/track_reuse.php';
 $rel=(string)($info['master_rel']??'');$job=(string)($info['job_id']??'');$aid=(string)($info['album_id']??'');
 if(!preg_match('/^[a-f0-9]{16}$/D',$aid)||!str_starts_with($rel,'hls_out/')||str_contains($rel,'..')||basename($rel)!=='master.m3u8')throw new RuntimeException('Ruta de salida inválida');
 $root=realpath(LAB_HLS);$base=realpath(dirname(LAB_ROOT.'/'.$rel));
 if(!$base)return;
 if(!$root||!str_starts_with($base,$root.'/')||basename($base)!==$job||is_link(dirname(LAB_ROOT.'/'.$rel)))throw new RuntimeException('La salida no pertenece a esta conversión');
 $reuse=sf_reuse_lock();$lock=null;
 try{
  $albumDir=LAB_ALBUMS.'/'.$aid;
  if(is_dir($albumDir)){$lock=fopen($albumDir.'/.processing-append.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Colección ocupada; limpieza aplazada');}
  $db=lab_auth_pdo();$prefix=dirname($rel).'/';$q=$db->prepare('SELECT 1 FROM tracks WHERE substr(hls_path,1,?)=? LIMIT 1');$q->execute([strlen($prefix),$prefix]);
  if($q->fetchColumn()){sf_media_event('output.cleanup_skipped',['album_id'=>$aid,'job_id'=>$job,'path'=>$rel,'reason'=>'referenced']);return;}
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($it as $item){if($item->isLink())throw new RuntimeException('Salida con enlace; se conserva para revisión');}
  sf_media_event('output.cleanup_started',['album_id'=>$aid,'job_id'=>$job,'path'=>$rel]);
  foreach($it as $item){$ok=$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());if(!$ok)throw new RuntimeException('No se pudo limpiar la conversión no publicada');}
  if(!rmdir($base))throw new RuntimeException('No se pudo retirar el directorio de salida');
  sf_media_event('output.unpublished_removed',['album_id'=>$aid,'job_id'=>$job,'path'=>$rel]);
 }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}flock($reuse,LOCK_UN);fclose($reuse);}
}
function sf_replace_publish(string $aid,string $tid,string $expected,array $new,array $tags,string $job,string $operation='replacement'):array{
 $file=realpath(LAB_ROOT.'/'.$new['hls']);$root=realpath(LAB_HLS);if(!$file||!$root||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||filesize($file)===0)throw new RuntimeException('Audio nuevo no disponible');
 $lock=fopen(LAB_ALBUMS.'/'.$aid.'/.processing-append.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Colección ocupada');
 try{
  $a=lab_load_album($aid);$idx=null;foreach($a['tracks']??[] as $i=>$t)if($t['id']===$tid)$idx=$i;if($idx===null)throw new RuntimeException('La pista fue eliminada o movida');$old=$a['tracks'][$idx];
  if(($old['replacement_job_id']??'')===$job)return $old;
  if(($old['hls']??'')!==$expected)throw new RuntimeException('Otra operación cambió la pista. No se sustituyó su audio');
  $backup=LAB_ROOT.'/backups/replacements';if(!is_dir($backup)&&!mkdir($backup,02770,true))throw new RuntimeException('No se pudo preparar el respaldo');
  $receiptFile=$backup.'/'.$job.'.json';
  $receipt=['version'=>2,'operation'=>$operation,'album_id'=>$aid,'track'=>$old,'replacement_hls'=>$new['hls'],'state'=>'prepared','prepared_at'=>time()];
  sf_replace_receipt($receiptFile,$receipt);
  sf_media_event('replacement.prepared',['album_id'=>$aid,'track_id'=>$tid,'task_id'=>$job,'source'=>$old['hls'],'destination'=>$new['hls']]);
  $merged=sf_replace_merge($old,$new,$tags,$job);$a['tracks'][$idx]=$merged;sf_replace_catalog_swap($a,$tid,$expected);
  sf_output_mark_published($merged,$aid);
  sf_media_event('replacement.published',['album_id'=>$aid,'track_id'=>$tid,'task_id'=>$job,'source'=>$old['hls'],'destination'=>$new['hls'],'reason'=>'old_audio_retained']);
  // Publication succeeded. Retirement is independent and never removes files in this worker.
  try{
   $receipt['state']='published';$receipt['published_at']=time();
   $receipt['retire_after']=time()+max(3600,(int)ceil((float)($old['duration']??0))+600);
   $receipt['retirement']='pending_verified_backup';sf_replace_receipt($receiptFile,$receipt);
  }catch(Throwable $cleanupError){sf_media_event('replacement.receipt_pending',['album_id'=>$aid,'track_id'=>$tid,'task_id'=>$job],$cleanupError);error_log('Replacement published; retirement receipt pending: '.$job);}
 }finally{flock($lock,LOCK_UN);fclose($lock);}
 try{require_once __DIR__.'/loudness.php';$p=lab_auth_pdo();$p->prepare('DELETE FROM sf_track_loudness WHERE track_id=?')->execute([$tid]);sf_loudness_analyze($merged);}catch(Throwable $e){error_log('Replacement loudness analysis pending');}
 return $merged;
}
