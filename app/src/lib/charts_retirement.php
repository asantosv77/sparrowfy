<?php
declare(strict_types=1);
require_once __DIR__.'/album_delete_transaction.php';
require_once __DIR__.'/delete_safety.php';
require_once __DIR__.'/track_reuse.php';
require_once __DIR__.'/player_queue_presence.php';
require_once __DIR__.'/charts_collections.php';

function sfc_retirement_busy(PDO $db,string $album):bool {
 foreach(['sf_processing_jobs','audio_edit_jobs'] as $table){
  $q=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);$exists=$q->fetchColumn();$q->closeCursor();if(!$exists)continue;
  $q=$db->prepare("SELECT 1 FROM $table WHERE album_id=? AND state IN ('queued','running') LIMIT 1");$q->execute([$album]);$busy=(bool)$q->fetchColumn();$q->closeCursor();if($busy)return true;
 }return false;
}
/** Caller must only supply a verified chart album; favorites of any account count. */
function sfc_retire_track(PDO $db,string $aid,string $tid):string {
 sfqp_schema($db);$lock=sf_reuse_lock();$batch=null;$pdo=null;$quarantines=[];$committed=false;
 try{
  $album=lab_load_album($aid);if(!$album||empty($album['music_chart']))throw new RuntimeException('La limpieza solo puede retirar pistas de un TOP');
  if(sfqp_defer_retirement($db,$album,$tid))return 'queued_in_use';
  if(sfc_retirement_busy($db,$aid))throw new RuntimeException('El TOP tiene tareas activas; se limpiará al terminar');
  $owned=null;foreach($album['tracks']??[] as $t)if(($t['id']??'')===$tid)$owned=$t;
  if(!$owned){$db->prepare('DELETE FROM sf_album_track_refs WHERE album_id=? AND track_id=?')->execute([$aid,$tid]);$db->prepare('DELETE FROM sf_chart_retire_pending WHERE album_id=? AND track_id=?')->execute([$aid,$tid]);return 'reference_removed';}
  // A track required by another active chart may not have its reference attached yet.
  $q=$db->prepare('SELECT DISTINCT c.chart_key,c.album_id FROM sf_chart_audio a JOIN sf_chart_entries e ON e.identity=a.identity JOIN sf_music_charts c ON c.chart_key=e.chart_key WHERE a.track_id=? AND c.enabled=1 AND COALESCE(json_extract(e.data,\'$.genre_review\'),0)=0 AND c.album_id<>?');$q->execute([$tid,$aid]);$targets=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
  foreach($targets as $target){$other=lab_load_album($target['album_id']);if($other&&($other['music_chart']??'')===$target['chart_key'])$db->prepare('INSERT OR IGNORE INTO sf_album_track_refs VALUES(?,?,?,?)')->execute([$target['album_id'],$aid,$tid,gmdate('c')]);}
  $batch=sf_reuse_delete_prepare($aid,$album);$album=$batch['albums'][$aid];$owned=null;foreach($album['tracks']??[] as $current)if(($current['id']??'')===$tid)$owned=$current;if(!$owned)throw new RuntimeException('La pista cambió antes de reservar el TOP');
  $pdo=hml_delete_sqlite_open_and_validate($aid,$album);if(!$pdo)throw new RuntimeException('Se requiere SQLite para retirar pistas del TOP');$batch['db']=$pdo;if(sfc_retirement_busy($pdo,$aid))throw new RuntimeException('Se inició una tarea en el TOP; se pospone la limpieza');
  $q=$pdo->prepare('SELECT 1 FROM favorites WHERE track_id=? LIMIT 1');$q->execute([$tid]);$favorite=(bool)$q->fetchColumn();$q->closeCursor();
  $q=$pdo->prepare('SELECT 1 FROM sf_album_track_refs WHERE source_album_id=? AND track_id=? LIMIT 1');$q->execute([$aid,$tid]);$shared=(bool)$q->fetchColumn();$q->closeCursor();
  $q=$pdo->prepare('SELECT 1 FROM playlist_tracks WHERE track_id=? LIMIT 1');$q->execute([$tid]);$playlist=(bool)$q->fetchColumn();$q->closeCursor();
  $target=sfcc_retirement_target($pdo,$aid,$tid,$batch['albums'],$playlist,$favorite);
  if($target!==null){
   require_once __DIR__.'/library_move.php';sf_move_track_impl($aid,$target,$tid,$batch);
   $q=$pdo->prepare('SELECT album_id FROM tracks WHERE id=?');$q->execute([$tid]);$owner=$q->fetchColumn();$q->closeCursor();
   $pdo->prepare('UPDATE sf_chart_audio SET source_album=? WHERE track_id=?')->execute([$owner,$tid]);
   $outcome=$target==='fffffffffffffffe'?($playlist?'playlist_to_loose':'favorite_to_loose'):'preserved_in_album';
  }else{
   $q=$pdo->prepare('SELECT 1 FROM tracks WHERE hls_path=? AND id<>? LIMIT 1');$q->execute([$owned['hls'],$tid]);$duplicate=$q->fetchColumn();$q->closeCursor();if($duplicate)throw new RuntimeException('El audio también pertenece a otro identificador; se requiere revisión');
   $dir=hml_delete_validate_track_dir($aid,$owned);$trash=dirname($dir).'/.hml-trash-'.basename($dir).'-'.bin2hex(random_bytes(5));
   $quarantines[]=['original'=>$dir,'quarantine'=>$trash];$batch['quarantines']=$quarantines;sf_reuse_delete_checkpoint($batch,'prepared');
   if(!rename($dir,$trash))throw new RuntimeException('No se pudo apartar el audio del TOP');sf_reuse_delete_checkpoint($batch,'quarantined');
   $album['tracks']=array_values(array_filter($album['tracks'],static fn($t)=>($t['id']??'')!==$tid));hml_delete_atomic_save_album($album);
   // Only unreferenced, non-favorite tracks outside all active queues reach deletion.
   foreach(['playlist_tracks','favorites','track_renditions'] as $table)$pdo->prepare("DELETE FROM $table WHERE track_id=?")->execute([$tid]);
   $q=$pdo->prepare('DELETE FROM tracks WHERE id=? AND album_id=?');$q->execute([$tid,$aid]);if($q->rowCount()!==1)throw new RuntimeException('La pista cambió durante el retiro');
   require_once __DIR__.'/sqlite_catalog_sync.php';lab_sqlite_sync_album($album,$pdo);
   $pdo->prepare("UPDATE sf_chart_audio SET state='retired',track_id=NULL,source_album=NULL,job_id=NULL,mornye_job_id=NULL,service=NULL,url=NULL,attempts='[]',error=NULL,updated_at=? WHERE track_id=?")->execute([gmdate('c'),$tid]);$outcome='deleted';
  }
  $pdo->prepare('DELETE FROM sf_chart_retire_pending WHERE album_id=? AND track_id=?')->execute([$aid,$tid]);
  $pdo->commit();$committed=true;
  try{sf_reuse_delete_checkpoint($batch,'committed');}catch(Throwable $e){error_log('Charts retirement journal: '.$e->getMessage());}
  foreach($quarantines as $quarantine)try{hml_delete_tree($quarantine['quarantine']);}catch(Throwable $e){error_log('Charts quarantine cleanup: '.$e->getMessage());}
  sf_media_event('charts.track_retired',['album_id'=>$aid,'track_id'=>$tid,'reason'=>$outcome]);return $outcome;
 }catch(Throwable $e){
  if(!$committed){$errors=hml_delete_rollback($pdo,$quarantines,null);if($batch){$errors=array_merge($errors,sf_reuse_delete_restore($batch));try{sf_reuse_delete_checkpoint($batch,$errors?'recovery_required':'rolled_back');}catch(Throwable $ignored){}}}
  throw $e;
 }finally{if($batch)foreach(array_reverse($batch['locks']) as $f){flock($f,LOCK_UN);fclose($f);}flock($lock,LOCK_UN);fclose($lock);}
}

/** Remove unchecked charts through the same retirement policy, never external owners. */
function sfc_retire_disabled(PDO $db,array $settings):void {
 $active=sfc_active($settings);$charts=$db->query('SELECT * FROM sf_music_charts')->fetchAll(PDO::FETCH_ASSOC);
 foreach($charts as $chart)$db->prepare('UPDATE sf_music_charts SET enabled=? WHERE chart_key=?')->execute([in_array($chart['chart_key'],$active,true)?1:0,$chart['chart_key']]);
 foreach($charts as $chart){
  if(in_array($chart['chart_key'],$active,true))continue;
  $key=$chart['chart_key'];$aid=$chart['album_id'];$db->prepare('UPDATE sf_music_charts SET enabled=0 WHERE chart_key=?')->execute([$key]);if(!$aid)continue;
  try{
   if(in_array($key,sfc_active(sfc_settings()),true))continue;
   $album=lab_load_album($aid);if($album&&($album['music_chart']??'')!==$key)throw new RuntimeException('El álbum asociado ya no corresponde a este TOP');
   $db->prepare("UPDATE sf_processing_jobs SET state='cancelled',stage='TOP desactivado',finished_at=?,updated_at=? WHERE album_id=? AND state='queued' AND NOT EXISTS(SELECT 1 FROM sf_chart_audio a JOIN sf_chart_entries e ON e.identity=a.identity JOIN sf_music_charts c ON c.chart_key=e.chart_key WHERE a.job_id=sf_processing_jobs.id AND c.enabled=1 AND COALESCE(json_extract(e.data,'$.genre_review'),0)=0 AND c.album_id<>?)")->execute([gmdate('c'),gmdate('c'),$aid,$aid]);
   if(sfc_retirement_busy($db,$aid))throw new RuntimeException('Esperando que termine una tarea activa antes de eliminar el TOP');
   foreach(($album?sf_reuse_view($album)['tracks']:[])??[] as $track){if(in_array($key,sfc_active(sfc_settings()),true))throw new RuntimeException('TOP reactivado; limpieza detenida');sfc_retire_track($db,$aid,$track['id']);}
   $album=lab_load_album($aid);
   if($album){if(!empty(sf_reuse_view($album)['tracks']))throw new RuntimeException('Quedan pistas por retirar');$lock=sf_reuse_lock();try{sf_delete_album_with_preservation($aid,$album);}finally{flock($lock,LOCK_UN);fclose($lock);}}
   $db->beginTransaction();try{$db->prepare('DELETE FROM sf_chart_entries WHERE chart_key=?')->execute([$key]);$db->prepare('UPDATE sf_music_charts SET album_id=NULL,last_week=NULL,error=NULL WHERE chart_key=?')->execute([$key]);$db->exec("UPDATE sf_chart_audio SET state='retired',job_id=NULL,mornye_job_id=NULL,service=NULL,url=NULL,attempts='[]',error=NULL WHERE track_id IS NULL AND NOT EXISTS(SELECT 1 FROM sf_chart_entries e JOIN sf_music_charts c ON c.chart_key=e.chart_key WHERE e.identity=sf_chart_audio.identity AND c.enabled=1 AND COALESCE(json_extract(e.data,\'$.genre_review\'),0)=0)");$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  }catch(Throwable $e){$db->prepare('UPDATE sf_music_charts SET error=? WHERE chart_key=?')->execute(['Limpieza pendiente: '.mb_substr($e->getMessage(),0,500),$key]);}
 }
}

function sfc_cancel_unselected(PDO $db):void {
 $db->exec("UPDATE sf_processing_jobs SET state='cancelled',stage='Fuera de la selección del TOP',finished_at=datetime('now'),updated_at=datetime('now') WHERE state='queued' AND EXISTS(SELECT 1 FROM sf_chart_audio a WHERE a.job_id=sf_processing_jobs.id) AND NOT EXISTS(SELECT 1 FROM sf_chart_audio a JOIN sf_chart_entries e ON e.identity=a.identity JOIN sf_music_charts c ON c.chart_key=e.chart_key WHERE a.job_id=sf_processing_jobs.id AND c.enabled=1 AND COALESCE(json_extract(e.data,'$.genre_review'),0)=0)");
 $db->exec("UPDATE sf_chart_audio SET state='retired',job_id=NULL,service=NULL,url=NULL,attempts='[]',error=NULL WHERE state='queued' AND job_id IN(SELECT id FROM sf_processing_jobs WHERE state='cancelled' AND stage='Fuera de la selección del TOP')");
}
