<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
umask(0007);
require dirname(__DIR__).'/lib/external_ai.php';
require dirname(__DIR__).'/lib/external_validation.php';
require dirname(__DIR__).'/lib/external_models.php';
require dirname(__DIR__).'/lib/external_review.php';
$dir=sfem_dir();$lock=fopen($dir.'/worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
try{
 sfem_json($dir.'/heartbeat.json',['checked_at'=>time()]);$settings=sfem_settings();$state=sfem_read_json($dir.'/schedule.json');$period=sfem_interval_seconds($settings);$view=sfem_schedule_view($settings,$state);if($state&&($state['next_attempt']??null)!==$view['next_attempt']){sfem_json($dir.'/schedule.json',$view);$state=$view;}$provider=$settings['providers'][$settings['provider']]??[];
 $waiting=false;foreach(glob($dir.'/job-*.json')?:[] as $candidate){$pendingState=sfem_read_json($candidate);if(in_array($pendingState['state']??'',['queued','running'],true))$waiting=true;}
 if(!$waiting&&!sfem_pending_review()&&!empty($settings['daily'])&&!empty($provider['token'])&&time()-(int)($state['last_attempt']??0)>=$period){
  $id=gmdate('Ymd-His').'-'.bin2hex(random_bytes(5));sfem_json($dir.'/job-'.$id.'.json',['id'=>$id,'state'=>'queued','trigger'=>'scheduled','actor'=>'scheduled','provider'=>$settings['provider'],'created_at'=>gmdate('c')]);sfem_json($dir.'/schedule.json',['last_attempt'=>time(),'next_attempt'=>time()+$period]);
 }
 $pending=[];foreach(glob($dir.'/job-*.json')?:[] as $file){$j=sfem_read_json($file);if(($j['state']??'')==='queued')$pending[$file]=$j;elseif(($j['state']??'')==='running'){ $j['state']='failed';$j['error']='El worker anterior se interrumpió; no se repitió la solicitud de pago.';$j['finished_at']=gmdate('c');sfem_json($file,$j);}}
 ksort($pending);if(!$pending)exit;$file=array_key_first($pending);$job=$pending[$file];$job['state']='running';$job['stage']='Revisando integraciones';$job['started_at']=gmdate('c');sfem_json($file,$job);sfem_json($dir.'/schedule.json',['last_attempt'=>time(),'next_attempt'=>time()+$period]);
 try{
  $provider=$settings['providers'][$job['provider']]??[];if(empty($provider['token']))throw new RuntimeException('Configura el token del proveedor.');if(empty($provider['model'])){$job['stage']='Consultando catálogo y resolviendo modelo automático';sfem_json($file,$job);$provider=sfem_resolve_model($job['provider']);}
  $bundle=sfem_bundle();$sources=$bundle['sources'];$expected=$bundle['hashes'];
  $diagnostics=['kind'=>'bounded static review','youtube_search'=>'not_probed'];
  try{require_once dirname(__DIR__).'/lib/external_services.php';$rows=sf_external_youtube_search('ABBA Dancing Queen');$diagnostics['youtube_search']='responded';$diagnostics['result_count']=count($rows);}catch(Throwable $e){$diagnostics['youtube_search']='unavailable_or_changed';}
  $skill=(string)file_get_contents(dirname(__DIR__).'/scripts/skills/sparrowfy-external-maintenance/SKILL.md');$guide=(string)file_get_contents(dirname(__DIR__).'/scripts/EXTERNAL_SERVICES.md');
  $prompt=json_encode(['purpose'=>'Revisión limitada de integraciones externas; devuelve JSON según la skill.','editable_files'=>$sources,'diagnostics'=>$diagnostics,'guide'=>$guide],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  if(strlen($prompt)>250000)throw new RuntimeException('El contexto excedió el límite de mantenimiento.');
  $job['api_requested']=true;$job['model']=$provider['model'];$job['accounting']=sfem_accounting($provider,[]);sfem_json($file,$job);
  $proposal=sfem_ai_request($provider,$skill,$prompt,(int)$settings['max_tokens'],null,static function(array $usage,array $accounting)use(&$job,$file){$job['usage']=$usage;$job['accounting']=$accounting;sfem_json($file,$job);});
  sfem_json($dir.'/proposal-'.$job['id'].'.json',['base_hashes'=>$expected,'proposal'=>$proposal]);$job['summary']=$proposal['summary'];$job['usage']=$proposal['usage'];
  $changes=array_filter($proposal['files'],static fn($body,$name)=>$body!==$sources[$name],ARRAY_FILTER_USE_BOTH);
  if(!$changes){$job['result']='no_changes';$job['stage']='Revisión terminada sin cambios';}
  elseif(!empty(sfem_settings()['auto_apply'])){if(sfem_pending_review())throw new RuntimeException('Otra actualización sigue pendiente de validación del administrador.');$job['stage']='Validando propuesta';sfem_json($file,$job);$job['publication']=sfem_apply($changes,$expected,'AI:'.$job['provider'],$job['summary']);$job['result']='applied';$job['review_state']='pending';$job['stage']='Actualización automática aplicada y respaldada; pendiente de validación del administrador';}
  else{$job['checks']=sfem_validate($changes);$job['result']='proposal';$job['stage']='Propuesta validada; aplicación automática desactivada';}
  $job['state']='done';
 }catch(Throwable $e){$job['state']='failed';$job['error']=$e instanceof JsonException?'La IA devolvió JSON inválido; no se aplicaron cambios.':$e->getMessage();$job['stage']='Revisión no aplicada';}
 if(!empty($job['api_requested']))$job['balance']=sfem_balance($provider);
 $job['finished_at']=gmdate('c');sfem_json($file,$job);
}finally{flock($lock,LOCK_UN);fclose($lock);}
