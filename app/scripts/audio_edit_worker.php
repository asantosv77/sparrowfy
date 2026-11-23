<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require dirname(__DIR__).'/lib/audio_jobs.php';
require_once dirname(__DIR__).'/lib/track_replace.php';
function sf_worker_job(PDO $db,array $job): void {
 sf_media_context(['task_id'=>(string)$job['id'],'album_id'=>(string)$job['album_id'],'operation'=>'audio_edit']);
 sf_media_event('audio_edit.started',['track_id'=>$job['track_id']]);
 $id=$job['id'];$tmp=null;$reconTmp=null;$newDir=null;$newInfo=null;$editTemp=null;
 try {
  $album=lab_load_album($job['album_id']);if (!$album) throw new RuntimeException('Álbum eliminado');
  $idx=null;foreach($album['tracks']??[] as $i=>$t) if (($t['id']??'')===$job['track_id']) {$idx=$i;break;}
  if ($idx===null) throw new RuntimeException('Pista eliminada');
  $track=$album['tracks'][$idx];$oldJob=(string)($track['job_id']??'');
  if(($track['replacement_job_id']??'')===$id){
   sf_media_event('audio_edit.recovered',['track_id'=>$job['track_id'],'path'=>$track['hls']]);
  $q=$db->prepare('UPDATE audio_edit_jobs SET state="done",stage="Edición finalizada",progress=100,finished_at=?,updated_at=? WHERE id=?');$now=gmdate('c');$q->execute([$now,$now,$id]);
   return;
  }
  $parameters=json_decode((string)$job['parameters'],true,512,JSON_THROW_ON_ERROR);
  if(isset($parameters['_expected_hls'])&&$parameters['_expected_hls']!==(string)$track['hls'])throw new RuntimeException('La fuente cambió desde el primer intento; crea una nueva edición');
  if(!isset($parameters['_expected_hls'])){$parameters['_expected_hls']=(string)$track['hls'];$job['parameters']=json_encode($parameters,JSON_THROW_ON_ERROR);$db->prepare('UPDATE audio_edit_jobs SET parameters=?,updated_at=? WHERE id=?')->execute([$job['parameters'],gmdate('c'),$id]);}
  $checked=sf_audio_edit_source_check($track);
  $dir=$checked['dir'];$info=$checked['info'];
  [$ffmpeg,$ffprobe]=lab_ffmpeg();if (!$ffmpeg||!$ffprobe) throw new RuntimeException('FFmpeg/FFprobe no disponibles');
  sf_jobs_update($db,$id,'Buscando archivo fuente',5);
  $editTemp=sf_edit_temp_begin($id,$job['album_id']);$tmp=$editTemp['file'];
  $source=(string)($info['source_file']??'');$src=$source!==''?$dir.'/'.$source:'';
  if (!$src || !is_file($src)) {
   sf_jobs_update($db,$id,'Reconstruyendo desde segmentos HLS de mayor calidad',null,'hls_reconstructed',(string)($info['highest']??''));
   $reconTmp=$editTemp['dir'].'/reconstructed.m4a';$rec=lab_reconstruct_source($oldJob,$reconTmp);
   $src=(string)($rec['reconstructed_path']??'');
   if ($src==='') $src=$dir.'/'.(string)($rec['reconstructed']??'reconstructed.m4a');
   if (str_starts_with($src, rtrim(sys_get_temp_dir(),'/\\').'/')) $reconTmp=$src;
   if (!is_file($src)) throw new RuntimeException('No se pudo reconstruir la fuente');
  } else sf_jobs_update($db,$id,'Usando archivo fuente conservado',10,'original');
  $probe=lab_probe_audio($src,$ffprobe);if (empty($probe['ok'])) throw new RuntimeException('Fuente ilegible');
  $duration=(float)($probe['duration']??0);$p=json_decode($job['parameters'],true);
  $start=(float)$p['trim_start'];$end=(float)$p['trim_end'];$fadeIn=(float)$p['fade_in'];$fadeOut=(float)$p['fade_out'];
  $remaining=$duration-$start-$end;
  if ($remaining<=0.25 || $fadeIn>$remaining || $fadeOut>$remaining) throw new RuntimeException('Los recortes o fundidos superan la duración del audio');

  $filters=[];if($fadeIn>0) $filters[]='afade=t=in:st=0:d='.$fadeIn;
  if($fadeOut>0) $filters[]='afade=t=out:st='.max(0,$remaining-$fadeOut).':d='.$fadeOut;
  sf_jobs_update($db,$id,'Aplicando recortes y fundidos con FFmpeg',20);
  $cmd=escapeshellarg($ffmpeg).' -nostdin -hide_banner -loglevel error -y -threads '.max(1,min(4,(int)(lab_load_config()['sf_jobs_ffmpeg_threads']??2))).' -filter_threads 1 -ss '.escapeshellarg((string)$start).' -i '.escapeshellarg($src).' -t '.escapeshellarg((string)$remaining).' -vn '.($filters?'-af '.escapeshellarg(implode(',',$filters)).' ':'').'-c:a aac -b:a 192k '.escapeshellarg($tmp);
  [$rc,$log]=lab_run($cmd);if ($rc!==0 || !is_file($tmp)) throw new RuntimeException('FFmpeg no pudo editar el audio: '.substr($log,-500));
  sf_jobs_update($db,$id,'Generando variantes HLS',50);
  $cfg=lab_load_config();$cfg['delete_source_after_convert']=false;
  $newInfo=lab_convert_to_hls($tmp,(string)($track['name']??'melodia').'.m4a',$cfg,['album_id'=>$job['album_id'],'album_title'=>(string)($album['title']??$album['name']??'album')]);
  $newDir=lab_find_job_dir((string)$newInfo['job_id']);
  if (!$newDir || !is_file($newDir.'/master.m3u8')) throw new RuntimeException('Fallo de verificación del HLS nuevo');
  foreach($newInfo['renditions'] as $rendition) {
   $playlist=$newDir.'/'.$rendition.'/prog.m3u8';
   if(!is_file($playlist) || !str_contains((string)file_get_contents($playlist),'#EXT-X-ENDLIST')) throw new RuntimeException('Variante HLS incompleta');
  }
  sf_jobs_update($db,$id,'Publicando edición en biblioteca',90);
  $newTrack=['job_id'=>$newInfo['job_id'],'hls'=>$newInfo['master_rel'],'duration'=>$newInfo['duration_sec'],'source_kbps'=>$newInfo['source_kbps'],'renditions'=>$newInfo['renditions'],'source_codec'=>$newInfo['source_codec']??'aac'];
  foreach(['source_url','source_service','source_mornye_url','source_mornye_job'] as $field)if(isset($track[$field]))$newTrack[$field]=$track[$field];
  $edited=sf_replace_publish($job['album_id'],$job['track_id'],(string)$track['hls'],$newTrack,[],$id,'audio_edit');
  if($edited['hls']!==$newInfo['master_rel']){try{sf_replace_remove_unpublished($newInfo);}catch(Throwable $cleanup){sf_media_event('audio_edit.cleanup_deferred',['path'=>$newInfo['master_rel']],$cleanup);}}
  sf_media_event('audio_edit.published',['track_id'=>$job['track_id'],'path'=>$edited['hls']]);
  $q=$db->prepare('UPDATE audio_edit_jobs SET state="done",stage="Edición finalizada",progress=100,finished_at=?,updated_at=? WHERE id=?');$now=gmdate('c');$q->execute([$now,$now,$id]);
 } catch(Throwable $e) {
  sf_media_event('audio_edit.failed',['track_id'=>$job['track_id'],'path'=>$newInfo['master_rel']??''],$e);
  if($newInfo){try{sf_replace_remove_unpublished($newInfo);}catch(Throwable $cleanup){sf_media_event('audio_edit.cleanup_deferred',['path'=>$newInfo['master_rel']],$cleanup);}}
  $q=$db->prepare('UPDATE audio_edit_jobs SET state="failed",stage="Error de procesamiento",error=?,finished_at=?,updated_at=? WHERE id=?');$now=gmdate('c');$q->execute([substr($e->getMessage(),-700),$now,$now,$id]);
 } finally {sf_edit_temp_close($editTemp);if($tmp && is_file($tmp))@unlink($tmp);if($reconTmp && is_file($reconTmp))@unlink($reconTmp);}
}
/** Reclama una edición en la cola compartida; se ejecuta solo en el hijo del supervisor. */
function sf_claim_next(PDO $db): ?array {
 $db->exec('BEGIN IMMEDIATE');
 try {
  $job=$db->query("SELECT * FROM audio_edit_jobs WHERE state='queued' ORDER BY created_at,id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  if($job) {
   $now=gmdate('c');
   $q=$db->prepare("UPDATE audio_edit_jobs SET state='running',started_at=COALESCE(started_at,?),stage='Iniciando procesamiento',updated_at=? WHERE id=? AND state='queued'");
   $q->execute([$now,$now,$job['id']]);
  }
  $db->exec('COMMIT');return $job?:null;
 } catch(Throwable $e) {try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}throw $e;}
}
if (defined('SFQ_EDIT_FUNCTIONS_ONLY')) return;
$db=sf_jobs_db();
$lock=fopen(sys_get_temp_dir().'/sparrowfy_audio_worker.lock','c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) exit(0);
$db->exec("UPDATE audio_edit_jobs SET state='queued',stage='Recuperando tarea tras interrupción',updated_at=datetime('now') WHERE state='running'");
while ($job=sf_claim_next($db)) sf_worker_job($db,$job);
