<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/media_log.php';

if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once dirname(__DIR__).'/lib/processing_queue.php';
define('SFQ_EDIT_FUNCTIONS_ONLY',true);
require_once __DIR__.'/audio_edit_worker.php';
function sfq_limits(): array {
 $c=lab_load_config();return [max(1,min(4,(int)($c['sf_jobs_global_limit']??1))),max(1,min(4,(int)($c['sf_jobs_conversion_limit']??1))),max(1,min(4,(int)($c['sf_jobs_edit_limit']??1)))];
}
function sfq_can_start(): bool {
 $c=lab_load_config();if(array_key_exists('sf_jobs_protect_resources',$c) && !$c['sf_jobs_protect_resources'])return true;
 // /proc/loadavg is load average over one minute, not percentage. Conservative for 4 cores.
 $load=sys_getloadavg();if(is_array($load) && $load[0]>3.2)return false;
 $mem=@file_get_contents('/proc/meminfo');if($mem && preg_match('/MemAvailable:\s+(\d+) kB/',$mem,$m) && (int)$m[1]<524288)return false;
 $temp='/sys/class/thermal/thermal_zone0/temp';if(is_readable($temp) && ((int)@file_get_contents($temp))>=70000)return false;
 return true;
}
function sfq_claim(PDO $db,string $category): ?array {
 $db->exec('BEGIN IMMEDIATE');
 try {
  $q=$db->prepare("SELECT * FROM sf_processing_jobs WHERE state='queued' AND category=? ORDER BY CASE WHEN operation='loudness' THEN 1 ELSE 0 END,created_at,id LIMIT 1");$q->execute([$category]);$job=$q->fetch(PDO::FETCH_ASSOC);
  if($job){$now=gmdate('c');$u=$db->prepare("UPDATE sf_processing_jobs SET state='running',stage='Iniciando',started_at=?,updated_at=? WHERE id=? AND state='queued'");$u->execute([$now,$now,$job['id']]);}
  $db->exec('COMMIT');return $job?:null;
 }catch(Throwable $e){
  try {$db->exec('ROLLBACK');} catch(Throwable $rollbackError) {}
  throw $e;
 }
}
function sfq_process(PDO $db,array $job): void {
 sf_media_context(['task_id'=>(string)$job['id'],'album_id'=>(string)$job['album_id'],'operation'=>(string)$job['operation']]);
 sf_media_event('job.started');
 sf_control_event('job.started',['job_id'=>$job['id'],'album_id'=>$job['album_id'],'user_id'=>$job['user_id'],'operation'=>$job['operation']]);
 $id=$job['id'];$payload=json_decode($job['payload'],true)?:[];$tmpDir=null;
 try {
  $album=lab_load_album($job['album_id']);if(!$album)throw new RuntimeException('Álbum eliminado');
  if($job['operation']==='download'){require_once dirname(__DIR__).'/lib/audio_download.php';sfd_process($db,$job,$payload);return;}
  if($job['operation']==='loudness'){
 require_once dirname(__DIR__).'/lib/loudness.php';$target=null;foreach($album['tracks']??[] as $t)if(($t['id']??'')===($payload['track_id']??'')){$target=$t;break;}if(!$target)throw new RuntimeException('Pista eliminada');sfq_update($db,$id,'Analizando sonoridad',30);sf_loudness_analyze($target);$q=$db->prepare('SELECT status FROM sf_track_loudness WHERE track_id=?');$q->execute([$target['id']]);$status=$q->fetchColumn();$q->closeCursor();if($status!=='ready')throw new RuntimeException('No se pudo medir la sonoridad');sfq_finish($db,$id,['track_id'=>$target['id'],'loudness'=>'ready']);return;
}
if(($job['operation']==='mornye'||!empty($payload['mornye_parent']))){$parent=(string)($payload['mornye_parent']??$id);foreach($album['tracks']??[] as $existing){if(($existing['source_mornye_job']??'')===$parent||($existing['replacement_job_id']??'')===$id){sfq_finish($db,$id,['track'=>$existing,'album_id'=>$job['album_id']]);require_once dirname(__DIR__).'/lib/mornye_import.php';try{sfmn_cleanup_published(['id'=>$parent,'album_id'=>$job['album_id']],$id);}catch(Throwable $e){sfmn_event('inbox.cleanup.pending.'.$parent,$e->getMessage());}return;}}}
$cfg=lab_load_config();$filename=(string)($payload['filename']??'pista.mp3');
  if($job['operation']==='mornye') {
   require_once dirname(__DIR__).'/lib/mornye_import.php';
   $download=sfmn_fetch_source($db,$job,$payload);$source=$download['source'];$tmpDir=$download['directory'];$filename=$download['filename'];
   if(!empty($payload['mornye_client_source'])){require_once dirname(__DIR__).'/lib/mornye_client.php';sfmn_stage_client_source($db,$job,$payload,$source,$filename,$cfg);return;}
   if(($job['category']??'')==='download'){$payload['mornye_download_ready']=true;$payload['mornye_parent']=$id;$db->prepare("UPDATE sf_processing_jobs SET state='queued',category='conversion',stage='Descarga lista; esperando conversión',progress=25,payload=?,updated_at=? WHERE id=?")->execute([json_encode($payload),gmdate('c'),$id]);return;}

  }elseif($job['operation']==='youtube') {
   define('SFQ_YOUTUBE_FUNCTIONS_ONLY',true);require_once dirname(__DIR__).'/api_youtube.php';
   sfq_update($db,$id,'Validando fuente de YouTube',5);
   $url=yt_validate_url((string)($payload['url']??''));
   $meta=yt_metadata($url,isset($payload['cookies'])?(string)$payload['cookies']:null);
   if(($meta['duration']??0)>10800)throw new RuntimeException('El video supera 3 horas');
   $selectedFormat=yt_validate_audio_format_id((string)($payload['format_id']??''),$meta);
   // En modo automático elegir el mejor formato de audio anunciado en la inspección.
   if($selectedFormat==='')$selectedFormat=(string)($meta['best_audio']['format_id']??'');
   if($selectedFormat==='')throw new RuntimeException('No hay formatos de audio disponibles');
   $sourceExt='';
   foreach((array)($meta['formats']??[]) as $entry)if(($entry['format_id']??'')===$selectedFormat){$sourceExt=strtolower((string)($entry['ext']??''));break;}
   if(!in_array($sourceExt,['m4a','webm','opus','mp3','ogg','aac','mp4'],true))throw new RuntimeException('Contenedor de audio no compatible: '.$sourceExt);
   $tmpDir=sys_get_temp_dir().'/sfq-yt-'.bin2hex(random_bytes(12));if(!mkdir($tmpDir,0700))throw new RuntimeException('No se pudo crear temporal');
   sfq_update($db,$id,'Descargando audio original',15);
   $args=[yt_bin(),'-f',$selectedFormat,'--no-warnings','--no-playlist','--no-progress','--match-filter','duration <= 10800','--max-filesize','500M','--socket-timeout','20','--retries','2','--fragment-retries','2','--output',$tmpDir.'/%(id)s.%(ext)s'];
   if(!empty($payload['cookies'])){$args[]='--cookies';$args[]=$payload['cookies'];}$args[]=$url;
   $r=yt_run($args,1200,1048576);if($r['code']!==0)throw new RuntimeException(yt_failure_message($r,!empty($payload['cookies'])));
   $files=[];
   foreach(scandir($tmpDir)?:[] as $entry){$candidate=$tmpDir.'/'.$entry;if(is_file($candidate)&&strtolower(pathinfo($entry,PATHINFO_EXTENSION))===$sourceExt&&filesize($candidate)>0)$files[]=$candidate;}
   if(count($files)!==1)throw new RuntimeException('No se encontró un único archivo de audio descargado ('.$sourceExt.')');
   $source=$files[0];
   $filename=yt_safe_filename((string)($payload['filename']??$meta['title']??'Pista'),$sourceExt);
  }elseif($job['operation']==='client_hls'){require_once dirname(__DIR__).'/lib/client_processing.php';$source='';}elseif($job['operation']==='upload') {
   $source=(string)($payload['file']??'');if(!is_file($source))throw new RuntimeException('Archivo subido no disponible');
  }else throw new RuntimeException('Operación desconocida');
  if(!empty($payload['client_source'])){
 require_once dirname(__DIR__).'/lib/client_processing.php';$sid=(string)$payload['client_source'];if(!preg_match('/^[a-f0-9]{32}$/D',$sid))throw new RuntimeException('Sesión inválida');$dir=sfwc_root().'/'.$sid;
 $m=json_decode((string)file_get_contents($dir.'/state.json'),true);if(($m['user']??'')!==$job['user_id'])throw new RuntimeException('Propietario inválido');$ext=strtolower(pathinfo($source,PATHINFO_EXTENSION));if(!in_array($ext,['m4a','webm','opus','mp3','ogg','aac','mp4'],true))throw new RuntimeException('Formato inválido');if(filesize($source)>128*1048576)throw new RuntimeException('La fuente supera 128 MiB; utiliza procesamiento del servidor');
 $dest=$dir.'/source.'.$ext;if(!copy($source,$dest))throw new RuntimeException('No se pudo conservar la fuente');chmod($dest,0600);$m['source_url']=$url;$m['source_service']='youtube';$m['source_file']=basename($dest);$m['filename']=yt_safe_filename($filename,$ext);$m['duration']=(float)($meta['duration']??0);$m['state']='source_ready';$m['updated']=time();sfwc_save($dir,$m);
 sfq_finish($db,$id,['source_id'=>$sid,'filename'=>$m['filename'],'duration'=>$m['duration'],'album_id'=>$job['album_id']]);return;
 }
 $replacementTags=[];
  if(!empty($payload['replace_track_id'])&&$job['operation']!=='client_hls'){
   [$probeCode,$probeOut]=lab_run('ffprobe -v error -show_entries format_tags -of json '.escapeshellarg($source));
   if($probeCode===0){$probe=json_decode($probeOut,true);$tags=array_change_key_case((array)($probe['format']['tags']??[]),CASE_LOWER);foreach(['title','artist','album','genre','year'] as $key)if(!empty($tags[$key]))$replacementTags[$key]=(string)$tags[$key];if(empty($replacementTags['year'])&&!empty($tags['date']))$replacementTags['year']=substr((string)$tags['date'],0,4);}
   if(isset($meta))foreach(['title','artist','album'] as $key)if(empty($replacementTags[$key])&&!empty($meta[$key]))$replacementTags[$key]=(string)$meta[$key];
  }
  sfq_update($db,$id,'Convirtiendo y segmentando HLS',40);
  $info=$job['operation']==='client_hls'?sfwc_convert($payload,$album,$cfg):lab_convert_to_hls($source,$filename,$cfg,['album_id'=>$album['id'],'album_title'=>(string)($album['title']??'album'),'worker_user_id'=>$job['user_id'],'worker_parent_id'=>$id]);
  $track=['id'=>lab_id(),'name'=>pathinfo($filename,PATHINFO_FILENAME),'original'=>$filename,'job_id'=>$info['job_id'],'hls'=>$info['master_rel'],'duration'=>$info['duration_sec'],'source_kbps'=>$info['source_kbps'],'renditions'=>$info['renditions']];
  if($job['operation']==='mornye'||!empty($payload['mornye_parent'])){$track['source_mornye_url']=$payload['url']??$payload['mornye_url']??'';$track['source_mornye_job']=$payload['mornye_parent']??$id;}
  require_once dirname(__DIR__).'/lib/track_origin.php';$track+=sf_track_origin_fields((string)$job['operation'],$payload,$meta??[]);
  if(isset($meta))$track['source_codec']=$info['source_codec']??$sourceExt;
  sfq_update($db,$id,'Publicando en biblioteca',90);if(!empty($payload['replace_track_id'])){require_once dirname(__DIR__).'/lib/track_replace.php';$track=sf_replace_publish($job['album_id'],(string)$payload['replace_track_id'],(string)$payload['expected_hls'],$track,$replacementTags,$id);}else sfq_append_track($job['album_id'],$track);
  if(($track['hls']??'')!==$info['master_rel']){try{require_once dirname(__DIR__).'/lib/track_replace.php';sf_replace_remove_unpublished($info);}catch(Throwable $cleanup){sf_media_event('output.cleanup_deferred',['path'=>$info['master_rel']],$cleanup);}}
  sfq_finish($db,$id,['track'=>$track,'album_id'=>$job['album_id']]);
  if(!empty($payload['mornye_parent'])){try{require_once dirname(__DIR__).'/lib/mornye_import.php';sfmn_cleanup_published(['id'=>$payload['mornye_parent'],'album_id'=>$job['album_id']],$id);}catch(Throwable $e){sfmn_event('inbox.cleanup.failed.'.$id,$e->getMessage());}}
  if($job['operation']==='mornye'){try{sfmn_cleanup_published($job);}catch(Throwable $cleanup){sfmn_event('inbox.cleanup.pending.'.$id,$cleanup->getMessage());}}
 }catch(Throwable $e){if($job['operation']==='mornye'){require_once dirname(__DIR__).'/lib/mornye_control.php';sfmn_event('import.failed.'.$id,$e->getMessage());}sf_media_event('job.exception',['path'=>$info['master_rel']??''],$e);if(isset($info)){try{require_once dirname(__DIR__).'/lib/track_replace.php';sf_replace_remove_unpublished($info);}catch(Throwable $cleanup){sf_control_error('output.cleanup',$cleanup);sf_media_event('output.cleanup_deferred',['path'=>$info['master_rel']??''],$cleanup);}}sf_control_error('job.exception',$e,['job_id'=>$id]);sfq_finish($db,$id,null,substr($e->getMessage(),0,700));}
 finally {
 if($job['operation']==='client_hls'){try{$sid=(string)($payload['client_session']??'');if(preg_match('/^[a-f0-9]{32}$/D',$sid)){$dir=sfwc_root().'/'.$sid;$lock=fopen($dir.'/.lock','c');if($lock&&flock($lock,LOCK_EX)){$m=json_decode((string)file_get_contents($dir.'/state.json'),true);$q=$db->prepare('SELECT state FROM sf_processing_jobs WHERE id=?');$q->execute([$id]);$m['state']=$q->fetchColumn();$m['updated']=time();sfwc_save($dir,$m);flock($lock,LOCK_UN);fclose($lock);}}}catch(Throwable $stateError){sf_control_error('client.state.error',$stateError);}}
 foreach(['file','cookies'] as $key)if(!empty($payload[$key]) && is_file($payload[$key]) && str_starts_with(realpath($payload[$key])?:'',realpath(sfq_dir()).'/'))@unlink($payload[$key]);
  if($tmpDir && is_dir($tmpDir)){foreach(glob($tmpDir.'/*')?:[] as $f)if(is_file($f))@unlink($f);@rmdir($tmpDir);}
 }
}
if(defined('SFQ_FUNCTIONS_ONLY'))return;
require_once dirname(__DIR__).'/lib/client_processing.php';if(!in_array('--child',$argv,true)){try{sfwc_cleanup();}catch(Throwable $e){sf_control_error('client.cleanup.error',$e);}}
$db=sfq_db();sf_jobs_db();

if(in_array('--child',$argv,true)) {
 $category='conversion';foreach($argv as $i=>$arg)if($arg==='--category' && isset($argv[$i+1]))$category=$argv[$i+1];
 if($category==='edit'){$job=sf_claim_next($db);if($job)sf_worker_job($db,$job);}
 else {$job=sfq_claim($db,$category==='download'?'download':'conversion');if($job)sfq_process($db,$job);}
 exit;
}
$lock=fopen(sys_get_temp_dir().'/sparrowfy_processing_supervisor.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
// Only this supervisor may manage both categories. Do not run the legacy edit cron concurrently.
$db->exec("UPDATE sf_processing_jobs SET state='queued',stage='Recuperando tras interrupción',updated_at=datetime('now') WHERE state='running'");
$db->exec("UPDATE audio_edit_jobs SET state='queued',stage='Recuperando tras interrupción',updated_at=datetime('now') WHERE state='running'");
$db->exec("UPDATE sf_processing_jobs SET category='download' WHERE state='queued' AND operation='mornye' AND json_extract(payload,'$.mornye_download_ready') IS NULL");
$active=[];$stop=false;
while(true){
 [$global,$conv,$edit]=sfq_limits();
 $counts=['conversion'=>0,'edit'=>0,'download'=>0];foreach($active as $entry)$counts[$entry['category']]++;
 $pendingConv=(int)$db->query("SELECT COUNT(*) FROM sf_processing_jobs WHERE state='queued' AND category='conversion'")->fetchColumn();
 $pendingEdit=(int)$db->query("SELECT COUNT(*) FROM audio_edit_jobs WHERE state='queued'")->fetchColumn();
 while(($counts['conversion']+$counts['edit'])<$global && sfq_can_start()) {
  $choices=[];
  if($pendingConv>0 && $counts['conversion']<$conv){$q=$db->query("SELECT CASE WHEN operation='loudness' THEN '9999-12-31' ELSE created_at END FROM sf_processing_jobs WHERE state='queued' AND category='conversion' ORDER BY CASE WHEN operation='loudness' THEN 1 ELSE 0 END,created_at LIMIT 1")->fetchColumn();$choices['conversion']=$q;}
  if($pendingEdit>0 && $counts['edit']<$edit){$q=$db->query("SELECT created_at FROM audio_edit_jobs WHERE state='queued' ORDER BY created_at LIMIT 1")->fetchColumn();$choices['edit']=$q;}
  if(!$choices)break;asort($choices);$category=array_key_first($choices);
  $proc=proc_open([PHP_BINARY,__FILE__,'--child','--category',$category],[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes);
  if(!is_resource($proc)){fwrite(STDERR,"No se pudo lanzar worker\n");$stop=true;break;}
  $active[]=['proc'=>$proc,'category'=>$category];$counts[$category]++;
  if($category==='conversion')$pendingConv--;else $pendingEdit--;
 }
 // One network-only Mornye worker; CPU conversion retains the configured global limits.
 if($counts['download']===0&&sfq_can_start()&&(int)$db->query("SELECT COUNT(*) FROM sf_processing_jobs WHERE state='queued' AND category='download'")->fetchColumn()>0){$proc=proc_open([PHP_BINARY,__FILE__,'--child','--category','download'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes);if(is_resource($proc))$active[]=['proc'=>$proc,'category'=>'download'];}
 if(!$active)break;
 foreach($active as $key=>$entry){$status=proc_get_status($entry['proc']);if(!$status['running']){proc_close($entry['proc']);unset($active[$key]);}}
 $active=array_values($active);if($stop)break;
 usleep(300000);
}

if((int)$db->query("SELECT COUNT(*) FROM sf_processing_jobs WHERE state IN ('queued','running')")->fetchColumn()===0){try{require_once dirname(__DIR__).'/lib/mornye_recovery.php';sfmn_schema($db);sfmn_quarantine_expired($db);}catch(Throwable $e){sf_control_error('mornye.recovery',$e);}}
