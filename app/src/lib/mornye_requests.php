<?php
declare(strict_types=1);
require_once __DIR__.'/mornye_import.php';
function sfmn_schema(PDO $db):void {
 $db->exec('PRAGMA synchronous=FULL');
 $db->exec('CREATE TABLE IF NOT EXISTS sf_mornye_requests (request_key TEXT PRIMARY KEY, album_id TEXT NOT NULL, source_url TEXT NOT NULL, target TEXT NOT NULL, job_id TEXT NOT NULL)');
 $db->exec('CREATE TABLE IF NOT EXISTS sf_mornye_batches (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, album_id TEXT NOT NULL, local_mode INTEGER NOT NULL, tracks TEXT NOT NULL, created_at INTEGER NOT NULL)');
 $db->exec('CREATE INDEX IF NOT EXISTS sfmn_batches_user ON sf_mornye_batches(user_id,created_at)');
}
/** An explicit submit can resume a failed source; completed/active tasks are never restarted. */
function sfmn_resume_failed_source(PDO $db,array $user,string $id,bool $local,string $title):bool {
 $q=$db->prepare("SELECT payload FROM sf_processing_jobs WHERE id=? AND user_id=? AND operation='mornye' AND state='failed'");$q->execute([$id,$user['id']]);$raw=$q->fetchColumn();if($raw===false)return false;
 $receipt=json_decode((string)@file_get_contents(sfq_dir().'/mornye-'.$id.'.json'),true)?:[];
 if(!empty($receipt['submitting']))throw new RuntimeException('Solicitud Mornye incierta. Revisa su cola antes de reenviar.');
 $payload=json_decode($raw,true);$payload['filename']=$title;
 if($local&&empty($payload['mornye_client_source'])){
  require_once __DIR__.'/client_processing.php';$sid=bin2hex(random_bytes(16));$dir=sfwc_root().'/'.$sid;if(!mkdir($dir,02770))throw new RuntimeException('No se pudo preparar la conversión local');
  $album=$db->prepare('SELECT album_id FROM sf_processing_jobs WHERE id=?');$album->execute([$id]);
  sfwc_save($dir,['id'=>$sid,'user'=>(string)$user['id'],'album_id'=>$album->fetchColumn(),'filename'=>$title,'job_id'=>$id,'state'=>'source_queued','created'=>time(),'updated'=>time()]);$payload['mornye_client_source']=$sid;
 }elseif(!$local)unset($payload['mornye_client_source']);
 $q=$db->prepare("UPDATE sf_processing_jobs SET state='queued',category='download',stage='Reanudando tarea existente',error=NULL,progress=0,finished_at=NULL,payload=?,label=?,updated_at=? WHERE id=? AND user_id=? AND state='failed'");
 $q->execute([json_encode($payload),$title,gmdate('c'),$id,$user['id']]);return $q->rowCount()===1;
}
/** Caller holds the Mornye submission lock. Repeated requests return the same durable job. */
function sfmn_enqueue(PDO $db,array $user,string $album,string $url,string $title,bool $local=false,string $target=''):array {
 $url=sf_external_mornye_url($url);if(!preg_match('/^[a-f0-9]{16}$/D',$album))throw new RuntimeException('Álbum inválido');$a=lab_load_album($album);if(!$a||($a['album_type']??'')==='online_links')throw new RuntimeException('Álbum no disponible');
 if($title===''||mb_strlen($title)>200||preg_match('/[\x00-\x1f\x7f]/',$title))throw new RuntimeException('Título inválido');
 $payload=['url'=>$url,'filename'=>$title];if($target!==''){$t=null;foreach($a['tracks']??[] as $x)if(($x['id']??'')===$target)$t=$x;if(!$t)throw new RuntimeException('Pista no encontrada');$payload+=['replace_track_id'=>$target,'expected_hls'=>$t['hls']];}
 $key=hash('sha256',$album.'|'.$url.'|'.$target);$q=$db->prepare('SELECT job_id FROM sf_mornye_requests WHERE request_key=?');$q->execute([$key]);$existing=$q->fetchColumn();if(!$existing){$legacy=$db->prepare("SELECT id FROM sf_processing_jobs WHERE operation='mornye' AND album_id=? AND json_extract(payload,'$.url')=? AND COALESCE(json_extract(payload,'$.replace_track_id'),'')=? ORDER BY created_at DESC LIMIT 1");$legacy->execute([$album,$url,$target]);$existing=$legacy->fetchColumn();if($existing)$db->prepare('INSERT OR IGNORE INTO sf_mornye_requests VALUES(?,?,?,?,?)')->execute([$key,$album,$url,$target,$existing]);}
 if($existing){$q=$db->prepare('SELECT * FROM sf_processing_jobs WHERE id=?');$q->execute([$existing]);$j=$q->fetch(PDO::FETCH_ASSOC);if($j){if($j['user_id']!==$user['id'])throw new RuntimeException('Esta fuente ya fue solicitada para el álbum por otra cuenta. Revisa las pistas o espera su incorporación.');$p=json_decode($j['payload'],true)?:[];$result=json_decode($j['result_json']??'null',true);$published=$result['track']['id']??null;$present=false;foreach($a['tracks']??[] as $t)if(($t['id']??'')===$published&&($t['hls']??'')===($result['track']['hls']??null))$present=true;
 if($j['state']==='failed'&&sfmn_resume_failed_source($db,$user,$j['id'],$local,$title)){$q->execute([$j['id']]);$j=$q->fetch(PDO::FETCH_ASSOC);$p=json_decode($j['payload'],true)?:[];}
 // Local source jobs finish before publication: retain their identity for resume, including failed jobs.
 if($j['state']!=='done'||$present||!empty($p['mornye_client_source']))return ['job_id'=>$j['id'],'id'=>$p['mornye_client_source']??'','album_id'=>$album,'reused'=>true,'state'=>$j['state']];}}
 $sid='';$dir=null;if($local){require_once __DIR__.'/client_processing.php';$sid=bin2hex(random_bytes(16));$dir=sfwc_root().'/'.$sid;if(!mkdir($dir,02770))throw new RuntimeException('No se pudo preparar la fuente');sfwc_save($dir,['id'=>$sid,'user'=>(string)$user['id'],'album_id'=>$album,'filename'=>$title,'state'=>'source_queued','created'=>time(),'updated'=>time()]);$payload['mornye_client_source']=$sid;}
 $id=sfq_add($db,(string)$user['id'],'download','mornye',$album,$title,$payload);$db->prepare('INSERT OR REPLACE INTO sf_mornye_requests VALUES(?,?,?,?,?)')->execute([$key,$album,$url,$target,$id]);if($sid!==''){$m=json_decode(file_get_contents($dir.'/state.json'),true);$m['job_id']=$id;sfwc_save($dir,$m);}return ['job_id'=>$id,'id'=>$sid,'album_id'=>$album,'state'=>'queued','reused'=>false];
}
function sfmn_submission_lock(){ $h=fopen(sfmn_dir().'/.submit.lock','c');if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('No se pudo reservar la importación');return $h; }
function sfmn_batch_status(PDO $db,array $batch):array {
 $tracks=json_decode($batch['tracks'],true);foreach($tracks as &$t){if(empty($t['job_id'])){$t+=['state'=>'awaiting_submission','stage'=>'Preparación pendiente: pulsa Reanudar','progress'=>0,'finished'=>false];continue;}$q=$db->prepare('SELECT state,stage,progress,error,result_json,payload FROM sf_processing_jobs WHERE id=?');$q->execute([$t['job_id']]);$j=$q->fetch(PDO::FETCH_ASSOC);if(!$j){$t+=['state'=>'failed','error'=>'Tarea no disponible'];continue;}$p=json_decode($j['payload'],true)?:[];$t['source_id']=$p['mornye_client_source']??'';
 if($t['source_id']!==''&&$j['state']==='done'){$q=$db->prepare("SELECT id,state,stage,progress,error,result_json FROM sf_processing_jobs WHERE json_extract(payload,'$.mornye_parent')=? ORDER BY created_at DESC LIMIT 1");$q->execute([$t['job_id']]);$final=$q->fetch(PDO::FETCH_ASSOC);if($final){$t['publication_id']=$final['id'];$j=$final;}else{$j['state']='awaiting_client';$j['stage']='Fuente lista. Reanuda la conversión en la PC.';$j['progress']=20;}}
 $t['state']=$j['state'];$t['stage']=$j['stage'];$t['progress']=(int)$j['progress'];$t['error']=$j['error'];$t['finished']=in_array($j['state'],['done','failed','cancelled'],true);
 }unset($t);return ['id'=>$batch['id'],'album'=>$batch['album_id'],'local'=>(bool)$batch['local_mode'],'jobs'=>$tracks];
}
