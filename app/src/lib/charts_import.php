<?php
declare(strict_types=1);
/** Protected orchestration: reuse catalogue tracks and call the established import queue. */
require_once __DIR__.'/external_services.php';
require_once __DIR__.'/mornye_requests.php';
require_once __DIR__.'/track_reuse.php';
require_once __DIR__.'/music_charts.php';
require_once __DIR__.'/charts_retirement.php';
require_once __DIR__.'/charts_best_effort.php';
function sfc_existing(PDO $db,array $song):?array {
 $term=mb_substr($song['artist'].' - '.$song['title'],0,160);$matches=sf_reuse_search($term,(float)($song['duration']??0));$valid=[];
 foreach($matches as $m){$live=sfc_live_track($db,$m['id']);if(!$live)continue;$t=$live['track'];$parts=sf_reuse_split_name((string)$t['name']);$candidate=['title'=>trim((string)($t['title']??''))?:$parts['title'],'artist'=>trim((string)($t['artist']??''))?:$parts['artist'],'duration'=>$t['duration']??0,'isrc'=>$t['isrc']??''];$fit=sf_chart_candidate($song,$candidate,'library');
  if(!$fit['confident'])continue;$rates=array_map('intval',$t['renditions']??[]);$quality=$rates?max($rates):0;$valid[]=$live+['quality'=>$quality,'match'=>$fit];
 }
 usort($valid,static fn($a,$b)=>($b['quality']<=>$a['quality'])?:($b['match']['score']<=>$a['match']['score']));return $valid[0]??null;
}
function sfc_audio_update(PDO $db,string $identity,array $values):void {
 $allowed=['data','state','track_id','source_album','job_id','mornye_job_id','service','url','attempts','error'];$set=[];$args=[];foreach($values as $k=>$v)if(in_array($k,$allowed,true)){$set[]=$k.'=?';$args[]=$v;}$set[]='updated_at=?';$args[]=gmdate('c');$args[]=$identity;
 $db->prepare('UPDATE sf_chart_audio SET '.implode(',',$set).' WHERE identity=?')->execute($args);
}
function sfc_try_source(PDO $db,array $row,array $settings,string $album):void {
 $song=json_decode($row['data'],true,32,JSON_THROW_ON_ERROR);$attempts=json_decode($row['attempts'],true)?:[];$identity=$row['identity'];
 if(empty($song['detail_checked'])){try{$song=sf_external_chart_detail($song,$song['market']??'mx');}catch(Throwable $e){}$song['detail_checked']=true;sfc_audio_update($db,$identity,['data'=>json_encode($song,JSON_UNESCAPED_UNICODE)]);}
 $found=sfc_existing($db,$song);if($found){sfc_audio_update($db,$identity,['state'=>'ready','track_id'=>$found['track']['id'],'source_album'=>$found['album_id'],'error'=>null]);return;}
 $query=mb_substr($song['artist'].' '.$song['title'],0,240);$reason=[];
 if(!in_array('mornye',$attempts,true)){
  $pick=null;try{$pick=sf_chart_pick($song,sf_external_mornye_search($query),'mornye');}catch(Throwable $e){$reason[]='Mornye: '.$e->getMessage();}
  if($pick){$lock=sfmn_submission_lock();try{
    $db->beginTransaction();$job=sfmn_enqueue($db,['id'=>$settings['owner_id']],$album,$pick['url'],mb_substr($song['artist'].' - '.$song['title'],0,190));
    if(($job['state']??'')==='cancelled')$db->prepare("UPDATE sf_processing_jobs SET state='queued',stage='En cola',progress=0,error=NULL,started_at=NULL,finished_at=NULL,updated_at=? WHERE id=? AND user_id=? AND album_id=? AND state='cancelled' AND stage IN ('TOP desactivado','Fuera de la selección del TOP')")->execute([gmdate('c'),$job['job_id'],$settings['owner_id'],$album]);
    $db->prepare("UPDATE sf_processing_jobs SET payload=json_set(payload,'$.chart_identity',?) WHERE id=?")->execute([$identity,$job['job_id']]);
    $attempts[]='mornye';sfc_audio_update($db,$identity,['state'=>'queued','job_id'=>$job['job_id'],'mornye_job_id'=>$job['job_id'],'service'=>'mornye','url'=>$pick['url'],'attempts'=>json_encode($attempts),'error'=>null]);$db->commit();
   }catch(Throwable $e){if($db->inTransaction())$db->rollBack();sfc_audio_update($db,$identity,['state'=>'review','error'=>'No se pudo confirmar la tarea Mornye: '.mb_substr($e->getMessage(),0,500)]);}
   finally{flock($lock,LOCK_UN);fclose($lock);}return;
  }
  $reason[]='Mornye: sin coincidencia inequívoca';
  $attempts[]='mornye';sfc_audio_update($db,$identity,['attempts'=>json_encode($attempts)]);
 }
 if(!in_array('youtube',$attempts,true)){
  $pick=null;try{$pick=sf_chart_pick($song,sf_external_youtube_search($query,null,3),'youtube');}catch(Throwable $e){$reason[]='YouTube: '.$e->getMessage();}
  if($pick){try{
    $payload=['url'=>$pick['url'],'filename'=>mb_substr($song['artist'].' - '.$song['title'],0,190),'format_id'=>'','chart_identity'=>$identity];
    $db->beginTransaction();$job=sfq_add($db,$settings['owner_id'],'download','youtube',$album,$payload['filename'],$payload);$attempts[]='youtube';sfc_audio_update($db,$identity,['state'=>'queued','job_id'=>$job,'service'=>'youtube','url'=>$pick['url'],'attempts'=>json_encode($attempts),'error'=>null]);$db->commit();
   }catch(Throwable $e){if($db->inTransaction())$db->rollBack();sfc_audio_update($db,$identity,['state'=>'review','error'=>'No se pudo confirmar la tarea YouTube: '.mb_substr($e->getMessage(),0,500)]);}return;
  }
  $reason[]='YouTube: sin coincidencia inequívoca';
  $attempts[]='youtube';
 }
 sfc_audio_update($db,$identity,['state'=>'review','attempts'=>json_encode($attempts),'error'=>mb_substr(implode(' · ',$reason)?:'Se agotaron las fuentes automáticas; requiere revisión',0,700)]);
}
function sfc_reconcile_audio(PDO $db):void {
 foreach($db->query("SELECT * FROM sf_chart_audio WHERE state='queued'")->fetchAll(PDO::FETCH_ASSOC) as $row){
  $q=$db->prepare('SELECT state,result_json,error FROM sf_processing_jobs WHERE id=?');$q->execute([$row['job_id']]);$job=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
  if(!$job){sfc_audio_update($db,$row['identity'],['state'=>'review','error'=>'La tarea ya no está disponible']);continue;}
  if($job['state']==='done'){$result=json_decode($job['result_json'],true)?:[];$id=$result['track']['id']??'';$live=$id?sfc_live_track($db,$id):null;
   if($live)sfc_audio_update($db,$row['identity'],['state'=>'ready','track_id'=>$id,'source_album'=>$live['album_id'],'error'=>null]);else sfc_audio_update($db,$row['identity'],['state'=>'review','error'=>'La tarea terminó sin confirmar audio publicado']);
  }elseif(in_array($job['state'],['failed','cancelled'],true))sfc_audio_update($db,$row['identity'],['state'=>$row['service']==='mornye'?'new':'review','error'=>mb_substr((string)$job['error'],0,700)]);
 }
 foreach($db->query("SELECT identity,track_id FROM sf_chart_audio WHERE state='ready'")->fetchAll(PDO::FETCH_ASSOC) as $row)if(!sfc_live_track($db,(string)$row['track_id']))sfc_audio_update($db,$row['identity'],['state'=>'review','error'=>'La pista fue retirada de la biblioteca; revisa antes de descargar otra copia']);
}
function sfc_sync_album(PDO $db,array $chart):void {
 $aid=$chart['album_id']??'';if(!$aid||!lab_load_album($aid))return;$rows=sfc_entry_rows($db,$chart['chart_key']);$desired=[];
 foreach($rows as $row)if($row['state']==='ready'&&$row['track_id']){$live=sfc_live_track($db,$row['track_id']);if(!$live)continue;$desired[]=$row['track_id'];if($live['album_id']!==$aid)sf_reuse_attach($live['album_id'],$row['track_id'],$aid);}
 // Keep the previous playable edition until at least one new track is available.
 if(!$desired)return;
 // Cancel a pending retirement if a track is selected again in this TOP.
 sfqp_schema($db);$q=$db->prepare('DELETE FROM sf_chart_retire_pending WHERE album_id=? AND track_id=?');foreach($desired as $tid)$q->execute([$aid,$tid]);
 $desired=array_values(array_unique($desired));$album=sf_reuse_view(lab_load_album($aid));
 foreach($album['tracks']??[] as $t)if(!in_array($t['id'],$desired,true)){
  // Never retire a just-published track before its parent job is reconciled.
  $q=$db->prepare("SELECT 1 FROM sf_processing_jobs WHERE album_id=? AND state IN ('queued','running') LIMIT 1");$q->execute([$aid]);$busy=(bool)$q->fetchColumn();$q->closeCursor();if($busy)continue;
  sfc_retire_track($db,$aid,$t['id']);
 }
 $album=sf_reuse_view(lab_load_album($aid));$visible=array_column($album['tracks']??[],'id');$order=array_merge(array_values(array_intersect($desired,$visible)),array_values(array_diff($visible,$desired)));
 if($order!==$visible)sf_album_order_save($aid,$order,$visible);
}
