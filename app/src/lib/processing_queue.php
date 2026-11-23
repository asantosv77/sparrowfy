<?php
declare(strict_types=1);
require_once __DIR__.'/media_log.php';

require_once dirname(__DIR__).'/lib.php';
require_once __DIR__.'/control_log.php';
function sfq_db(): PDO {
 $db=lab_auth_pdo();
 $db->exec('CREATE TABLE IF NOT EXISTS sf_processing_jobs (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, category TEXT NOT NULL, operation TEXT NOT NULL, album_id TEXT NOT NULL, label TEXT NOT NULL, payload TEXT NOT NULL, state TEXT NOT NULL, stage TEXT NOT NULL, progress INTEGER, error TEXT, result_json TEXT, created_at TEXT NOT NULL, started_at TEXT, finished_at TEXT, updated_at TEXT NOT NULL)');
 $db->exec('CREATE INDEX IF NOT EXISTS sfq_pending ON sf_processing_jobs(state,created_at)');
 return $db;
}
function sfq_dir(): string {
 $dir=dirname(__DIR__).'/data/processing_jobs';
 if(!is_dir($dir) && !@mkdir($dir,0770,true) && !is_dir($dir)) throw new RuntimeException('No se puede crear directorio de cola');
 return $dir;
}
function sfq_add(PDO $db,string $user,string $category,string $operation,string $album,string $label,array $payload): string {
 $id=bin2hex(random_bytes(16));$now=gmdate('c');
 $q=$db->prepare('INSERT INTO sf_processing_jobs(id,user_id,category,operation,album_id,label,payload,state,stage,progress,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
 $q->execute([$id,$user,$category,$operation,$album,substr($label,0,200),json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'queued','En cola',0,$now,$now]);
 sf_media_event('job.queued',['task_id'=>$id,'album_id'=>$album,'operation'=>$operation]);
 sf_control_event('job.queued',['job_id'=>$id,'user_id'=>$user,'album_id'=>$album,'operation'=>$operation]);
 return $id;
}
function sfq_update(PDO $db,string $id,string $stage,int $progress): void {
 sf_control_event('job.stage',['job_id'=>$id,'stage'=>$stage,'progress'=>$progress]);
 $q=$db->prepare('UPDATE sf_processing_jobs SET stage=?,progress=?,updated_at=? WHERE id=?');$q->execute([$stage,$progress,gmdate('c'),$id]);
}
function sfq_finish(PDO $db,string $id,?array $result,?string $error=null): void {

 $now=gmdate('c');$q=$db->prepare('UPDATE sf_processing_jobs SET state=?,stage=?,progress=?,result_json=?,error=?,finished_at=?,updated_at=? WHERE id=?');
 $q->execute([$error===null?'done':'failed',$error===null?'Finalizado':'Error',$error===null?100:null,$result===null?null:json_encode($result,JSON_UNESCAPED_UNICODE),$error,$now,$now,$id]);
 sf_media_event($error===null?'job.completed':'job.failed',['task_id'=>$id,'path'=>$result['track']['hls']??'','track_id'=>$result['track']['id']??'']);
 sf_control_event($error===null?'job.completed':'job.failed',['job_id'=>$id,'error_fingerprint'=>$error===null?'':hash('sha256',$error)]);
 if($error===null&&!empty($result['track']['id'])&&!empty($result['track']['hls'])&&(!empty($result['track']['source_mornye_job'])||($result['track']['source_service']??'')==='mornye')){
  try{require_once __DIR__.'/mornye_event_resolution.php';sfmn_reconcile_events($db,sfq_dir(),dirname(__DIR__).'/data/mornye-control/events.json');}
  catch(Throwable $noticeError){error_log('Mornye notice reconciliation deferred: '.get_class($noticeError));}
 }
}
function sfq_append_track(string $albumId,array $track): void {
 require_once __DIR__.'/loudness.php';
 try{sf_loudness_analyze($track);}catch(Throwable $e){error_log('Loudness analysis failed: '.get_class($e));}
 $dir=dirname(__DIR__).'/data/albums/'.$albumId;
 $lock=fopen($dir.'/.processing-append.lock','c');if(!$lock || !flock($lock,LOCK_EX))throw new RuntimeException('No se pudo bloquear álbum');
 try {
  $album=lab_load_album($albumId);if(!$album)throw new RuntimeException('Álbum eliminado');
  sf_media_event('catalog.publish_started',['album_id'=>$albumId,'track_id'=>$track['id'],'job_id'=>$track['job_id']??'','path'=>$track['hls']]);
  $album['tracks'][]=$track;lab_save_album($album);
  require_once __DIR__.'/sqlite_catalog_sync.php';lab_sqlite_sync_album($album);
  sf_output_mark_published($track,$albumId);
  sf_media_event('catalog.published',['album_id'=>$albumId,'track_id'=>$track['id'],'job_id'=>$track['job_id']??'','path'=>$track['hls']]);
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
