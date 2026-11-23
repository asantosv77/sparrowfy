<?php
declare(strict_types=1);
/** TOP-only reporting policy. It does not change manual imports or provider priority. */
function sfc_safe_diagnostic(string $value):string {
 $value=preg_replace('~https?://\S+~','[enlace omitido]',$value);
 $value=preg_replace('/(?i)(token|authorization|cookie|secret|password)\s*[:=]\s*\S+/','$1=[omitido]',(string)$value);
 return mb_substr(strip_tags((string)$value),0,1200);
}
function sfc_coverage(array $rows):array {
 $c=['requested'=>0,'available'=>0,'pending'=>0,'unavailable'=>0,'filtered'=>0];
 foreach($rows as $r){if(!empty($r['song']['genre_review'])||($r['state']??'')==='genre_review'){$c['filtered']++;continue;}$c['requested']++;
  if(($r['state']??'')==='ready')$c['available']++;elseif(in_array($r['state']??'',['new','queued'],true))$c['pending']++;else$c['unavailable']++;
 }
 $c['state']=$c['requested']===0?'filtered':($c['pending']?'working':($c['available']?($c['unavailable']?'partial':'complete'):'failed'));return $c;
}
function sfc_failure_stage(string $message):string {
 $m=mb_strtolower($message);
 foreach(['public'=>'Publicación','convers'=>'Conversión','sql'=>'Persistencia / SQLite','verific'=>'Verificación requerida','session'=>'Sesión','sesión'=>'Sesión','429'=>'Límite HTTP 429','404'=>'Recurso HTTP 404','timeout'=>'Tiempo de espera','coincidencia'=>'Sin coincidencia segura'] as $term=>$label)if(str_contains($m,(string)$term))return $label;
 if(preg_match('/\b5\d\d\b/',$m))return 'Servicio HTTP 5xx';return 'Búsqueda o descarga';
}
function sfc_best_effort_schema(PDO $db):void {
 $db->exec('CREATE TABLE IF NOT EXISTS sf_chart_outcomes(batch_id TEXT PRIMARY KEY,chart_key TEXT NOT NULL,edition TEXT NOT NULL,state TEXT NOT NULL,summary TEXT NOT NULL,diagnostic TEXT,updated_at INTEGER NOT NULL,notified INTEGER NOT NULL DEFAULT 0)');
 $db->exec('CREATE INDEX IF NOT EXISTS sf_chart_outcome_recent ON sf_chart_outcomes(updated_at)');
}
function sfc_best_effort_report(PDO $db,array $settings):void {
 require_once __DIR__.'/mornye_control.php';sfc_best_effort_schema($db);
 $cached=json_decode((string)@file_get_contents(sfmn_dir().'/status.json'),true)?:[];
 foreach($db->query('SELECT * FROM sf_music_charts WHERE enabled=1')->fetchAll(PDO::FETCH_ASSOC) as $chart){
  $rows=sfc_entry_rows($db,$chart['chart_key']);if(!$rows)continue;$c=sfc_coverage($rows);
  // A paused automatic downloader is not a failure; queued work stays pending.
  if(empty($settings['auto_download'])&&$c['state']==='failed')continue;
  $batch=hash('sha256',$chart['chart_key'].'|'.$chart['last_week'].'|'.json_encode(array_map(static fn($r)=>[$r['rank'],$r['song']['source_url']??'',$r['song']['title']??''],$rows)));
  $diagnostic=null;
  if($c['state']==='failed'){
   $details=[];$causes=[];$services=[];$jobs=[];$reused=0;$downloaded=0;$published=0;
   foreach($rows as $r){if(($r['state']??'')==='genre_review')continue;
    $msg=sfc_safe_diagnostic((string)($r['error']??'Sin fuente disponible'));$stage=sfc_failure_stage($msg);$causes[$stage]=($causes[$stage]??0)+1;
    $details[]=['track'=>mb_substr(($r['song']['artist']??'').' - '.($r['song']['title']??''),0,240),'stage'=>$stage,'detail'=>$msg,'attempts'=>json_decode((string)($r['attempts']??'[]'),true)?:[]];
    if(!empty($r['mornye_job_id'])){
     $q=$db->prepare('SELECT error FROM sf_processing_jobs WHERE id=?');$q->execute([$r['mornye_job_id']]);$prior=(string)$q->fetchColumn();if($prior!=='')$details[count($details)-1]['mornye_attempt_error']=sfc_safe_diagnostic($prior);
    }
    if(!empty($r['job_id']))$jobs[]=$r['job_id'];if(!empty($r['service']))$services[$r['service']]=true;
   }
   if($jobs){$q=$db->prepare('SELECT operation,payload,result_json,stage,state FROM sf_processing_jobs WHERE id IN ('.implode(',',array_fill(0,count($jobs),'?')).')');$q->execute($jobs);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $j){$p=json_decode((string)$j['payload'],true)?:[];$res=json_decode((string)$j['result_json'],true)?:[];if(!empty($res['track']['id']))$published++;if(!empty($res['source'])||!empty($p['source_path'])||str_contains(mb_strtolower((string)$j['stage']),'convers'))$downloaded++;}
   }
   $diagnostic=['chart'=>$chart['title'],'market'=>$chart['market'],'edition'=>$chart['last_week'],'source_updated'=>$chart['source_updated'],'batch_id'=>$batch,'counts'=>$c+['reused'=>$reused,'downloaded_confirmed'=>$downloaded,'published_confirmed'=>$published],'counts_note'=>'Las descargas solo se cuentan cuando el resultado conservado permite confirmarlas; no se deducen de una petición iniciada.','causes'=>$causes,'services'=>array_keys($services),'mornye_version'=>$cached['version']??'No identificada','extensions'=>$cached['extension_versions']??[],'versions_checked'=>$cached['versions_checked']??null,'recommendation'=>'Revisar las causas agrupadas. Verificar sesión o disponibilidad del proveedor según el detalle. Una actualización de Mornye solo se recomienda tras comparar la versión instalada y las notas oficiales; este fallo no demuestra que esté desactualizado.','tracks'=>array_slice($details,0,100)];
  }
  $db->prepare('INSERT INTO sf_chart_outcomes(batch_id,chart_key,edition,state,summary,diagnostic,updated_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(batch_id) DO UPDATE SET state=excluded.state,summary=excluded.summary,diagnostic=excluded.diagnostic,updated_at=excluded.updated_at')->execute([$batch,$chart['chart_key'],(string)$chart['last_week'],$c['state'],json_encode($c),$diagnostic?json_encode($diagnostic,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null,time()]);
  if($c['state']==='failed'){
   $q=$db->prepare('SELECT notified FROM sf_chart_outcomes WHERE batch_id=?');$q->execute([$batch]);
   if(!(bool)$q->fetchColumn()){
    sfmn_event('charts.audio.failed.'.$batch,'No se obtuvo ninguna pista disponible de '.$chart['title'].' ('.$c['requested'].' solicitudes, edición '.$chart['last_week'].'). Consulta Configuración → Music Charts → Diagnóstico de fallos totales. Causas: '.implode(', ',array_keys($diagnostic['causes'])));
    $db->prepare('UPDATE sf_chart_outcomes SET notified=1 WHERE batch_id=?')->execute([$batch]);
   }
  }
 }
 // Bounded technical history, separate from user-facing per-song alerts.
 $db->exec('DELETE FROM sf_chart_outcomes WHERE batch_id NOT IN (SELECT batch_id FROM sf_chart_outcomes ORDER BY updated_at DESC LIMIT 100)');
}
