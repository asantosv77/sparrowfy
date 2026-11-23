<?php
declare(strict_types=1);
// Persistent UI batches, independent from media import recovery.
function sflp_schema(PDO $db):void {
 $db->exec('CREATE TABLE IF NOT EXISTS sf_loudness_progress_batches(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id TEXT NOT NULL,job_ids TEXT NOT NULL,created_at TEXT NOT NULL)');
 $db->exec('CREATE INDEX IF NOT EXISTS sflp_owner ON sf_loudness_progress_batches(user_id,id)');
}
function sflp_latest(PDO $db,string $user):?array {
 $q=$db->prepare('SELECT id,job_ids,created_at FROM sf_loudness_progress_batches WHERE user_id=? ORDER BY id DESC LIMIT 1');$q->execute([$user]);$row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
}
function sflp_capture(PDO $db,string $user):void {
 sflp_schema($db);$db->exec('BEGIN IMMEDIATE');
 try{
  $q=$db->query("SELECT id FROM sf_processing_jobs WHERE operation='loudness' AND state IN ('queued','running') ORDER BY created_at,id");$ids=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
  $old=sflp_latest($db,$user);$prior=$old?json_decode($old['job_ids'],true):[];
  if($ids && (!$old || array_diff($ids,$prior))){
   $q=$db->prepare('INSERT INTO sf_loudness_progress_batches(user_id,job_ids,created_at) VALUES(?,?,?)');$q->execute([$user,json_encode(array_values($ids)),gmdate('c')]);
   $q=$db->prepare('DELETE FROM sf_loudness_progress_batches WHERE user_id=? AND id NOT IN (SELECT id FROM sf_loudness_progress_batches WHERE user_id=? ORDER BY id DESC LIMIT 20)');$q->execute([$user,$user]);
  }
  $db->exec('COMMIT');
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();else{try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}}throw $e;}
}
function sflp_status(PDO $db,string $user):array {
 $batch=sflp_latest($db,$user);$out=['total'=>0,'queued'=>0,'running'=>0,'done'=>0,'failed'=>0,'missing'=>0,'percent'=>0,'active'=>false,'current'=>null,'batch_id'=>null,'failures'=>[]];if(!$batch)return $out;
 $ids=json_decode($batch['job_ids'],true);if(!is_array($ids)||!$ids)return $out;
 $out['batch_id']=(int)$batch['id'];$out['total']=count($ids);$sum=0;$seen=0;
 foreach(array_chunk($ids,400) as $chunk){
  $q=$db->prepare("SELECT id,label,state,stage,progress,error,started_at,finished_at FROM sf_processing_jobs WHERE operation='loudness' AND id IN (".implode(',',array_fill(0,count($chunk),'?')).')');$q->execute($chunk);
  while($row=$q->fetch(PDO::FETCH_ASSOC)){
   if($row['state']==='failed' && count($out['failures'])<50)$out['failures'][]=['job_id'=>(string)$row['id'],'label'=>(string)$row['label']]+sflp_failure_reason($row);
   $seen++;$state=(string)$row['state'];if(!in_array($state,['queued','running','done','failed'],true)){$out['missing']++;$sum+=100;continue;}$out[$state]++;
   $sum+=in_array($state,['done','failed'],true)?100:($state==='running'?min(99,max(0,(int)$row['progress'])):0);
   if($state==='running' || ($state==='queued' && $out['current']===null))$out['current']=['label'=>(string)$row['label'],'stage'=>(string)$row['stage'],'progress'=>(int)$row['progress']];
  }
 }
 $out['missing']+=$out['total']-$seen;$sum+=100*($out['total']-$seen);
 $out['percent']=round($sum/$out['total'],1);$out['active']=$out['queued']+$out['running']>0;
 return $out;
}

// Never return raw exception strings (which could contain paths or SQL).
function sflp_failure_reason(array $job):array {
 $error=(string)($job['error']??'');$seconds=null;
 if(preg_match('/superó su límite de ([0-9]+) segundos/u',$error,$m))$seconds=(int)$m[1];
 $elapsed=!empty($job['started_at'])&&!empty($job['finished_at'])?strtotime($job['finished_at'])-strtotime($job['started_at']):0;
 if($seconds!==null)return ['kind'=>'timeout','reason'=>'Time-out alcanzado: '.$seconds.' segundos.','action'=>'Elige el modo Fijo, aumenta los minutos de time-out y guarda. Después pulsa Analizar pendientes. El cambio no reinicia ni amplía una tarea que ya esté ejecutándose.'];
 if($error==='No se pudo medir la sonoridad' && $elapsed>=179 && $elapsed<=181)return ['kind'=>'timeout_legacy','reason'=>'Probable time-out del límite anterior de 180 segundos.','action'=>'Vuelve a analizar con el modo Automático actualizado o configura un tiempo Fijo mayor.'];
 if(str_contains($error,'FFmpeg no devolvió')||$error==='No se pudo medir la sonoridad')return ['kind'=>'measurement','reason'=>'FFmpeg no pudo obtener una medición válida del audio.','action'=>'Comprueba que la pista se reproduzca completa. Si falla, revisa su fuente o reemplázala antes de volver a analizar. Aumentar el time-out no corrige un archivo dañado.'];
 return ['kind'=>'other','reason'=>'El análisis no pudo completarse.','action'=>'Comprueba que la pista y su colección sigan disponibles. Si vuelve a fallar, revisa el registro de operaciones antes de reintentar.'];
}
