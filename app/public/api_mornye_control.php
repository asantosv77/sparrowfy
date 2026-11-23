<?php
declare(strict_types=1);
require __DIR__.'/lib.php';lab_auth_require_admin();require_once __DIR__.'/lib/mornye_control.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
 $action=(string)($_POST['action']??'list');
 if($_SERVER['REQUEST_METHOD']==='POST'){lab_require_same_origin_post();if(!lab_auth_verify_csrf((string)($_POST['csrf_token']??'')))throw new RuntimeException('Sesion de formulario caducada. Recarga el panel.');}
 elseif($action!=='list')throw new RuntimeException('Metodo no permitido');
 if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
 $cached=json_decode((string)@file_get_contents(sfmn_dir().'/status.json'),true)?:[];
 if($action==='monitor'&&time()-(int)($cached['checked']??0)<60)$action='list';
 if($action==='ack'){
  $lock=fopen(sfmn_dir().'/.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Registro ocupado');
  try{$rows=json_decode((string)@file_get_contents(sfmn_dir().'/events.json'),true)?:[];foreach($rows as &$row)if(($row['id']??'')===($_POST['id']??''))$row['read']=true;unset($row);sfmn_write(sfmn_dir().'/events.json',$rows);}finally{flock($lock,LOCK_UN);fclose($lock);}
 }elseif(in_array($action,['probe','monitor','save'],true)){
  try{
   $info=sfmn_request('info');$available=sfmn_request('providers')['services']??[];$cfg=sfmn_request('settings');
   $ids=array_column($available,'id');
   if($action==='save'){
    $services=json_decode((string)($_POST['services']??''),true);$concurrency=filter_var($_POST['concurrency']??null,FILTER_VALIDATE_INT);
    if(!is_array($services)||!$services||count($services)>15||count(array_unique($services,SORT_REGULAR))!==count($services))throw new RuntimeException('Selecciona proveedores sin repetir.');
    foreach($services as $service)if(!is_string($service)||!in_array($service,$ids,true))throw new RuntimeException('Proveedor no instalado');
    if($concurrency===false||$concurrency<1||$concurrency>4)throw new RuntimeException('Concurrencia permitida: de 1 a 4');
    $changes=['services'=>$services,'max_concurrent_downloads'=>$concurrency,'allow_fallback'=>($_POST['fallback']??'')==='1'];
    $before=[];foreach($changes as $k=>$v)$before[$k]=$cfg[$k]??null;
    sfmn_write(sfmn_dir().'/preferences-before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json',$before);
    sfmn_request('save',['cfg'=>array_replace($cfg,$changes)]);$check=sfmn_request('settings');foreach($changes as $k=>$v)if(($check[$k]??null)!==$v)throw new RuntimeException('Mornye no confirmo las preferencias guardadas');
    $cfg=$check;sfmn_event('preferences.saved','Preferencias de proveedores y concurrencia guardadas y verificadas.','info');
   }
   $extensionVersions=(array)($cached['extension_versions']??[]);$versionsChecked=$cached['versions_checked']??null;
   try{$health=sfmn_request('extensions');$extensionVersions=[];foreach(array_slice((array)($health['providers']??[]),0,40) as $provider)if(!empty($provider['installed']))$extensionVersions[mb_substr((string)($provider['provider']??''),0,80)]=mb_substr((string)($provider['version']??'No identificada'),0,40);$versionsChecked=time();}catch(Throwable $ignored){}
   $state=['extension_versions'=>$extensionVersions,'versions_checked'=>$versionsChecked,'online'=>true,'version'=>mb_substr((string)($info['version']??''),0,40),'checked'=>time(),'providers'=>array_map(static fn($v)=>['id'=>(string)$v['id'],'label'=>(string)($v['label']??$v['id'])],$available),'services'=>array_values((array)($cfg['services']??[])),'fallback'=>(bool)($cfg['allow_fallback']??false),'concurrency'=>(int)($cfg['max_concurrent_downloads']??1)];
   try{$jobs=sfmn_request('jobs')['jobs']??[];foreach(array_slice($jobs,0,50) as $job){if(($job['status']??'')==='failed')sfmn_event('download.failed.'.substr((string)($job['id']??'unknown'),0,40),(string)($job['error']??'Mornye notifico una descarga fallida. Revisa su interfaz.'));}}catch(Throwable $jobsError){sfmn_event('jobs.unavailable','No se pudo consultar el estado de las descargas de Mornye.');}
   sfmn_write(sfmn_dir().'/status.json',$state);
  }catch(Throwable $e){sfmn_event('service.'.$action.'.failed',$e->getMessage());if($action!=='save')sfmn_write(sfmn_dir().'/status.json',['online'=>false,'checked'=>time()]);throw $e;}
 }elseif(!in_array($action,['list','ack'],true))throw new RuntimeException('Accion no permitida');
 $events=json_decode((string)@file_get_contents(sfmn_dir().'/events.json'),true)?:[];$state=json_decode((string)@file_get_contents(sfmn_dir().'/status.json'),true)?:null;
 try{require_once __DIR__.'/lib/processing_queue.php';require_once __DIR__.'/lib/mornye_event_resolution.php';$events=sfmn_reconcile_events(sfq_db(),sfq_dir(),sfmn_dir().'/events.json');}catch(Throwable $resolutionError){error_log('Mornye event resolution unavailable: '.get_class($resolutionError));foreach($events as &$event)if(preg_match('/^(?:import\.(?:failed|retry|paused)|download\.failed)\./',(string)($event['code']??''))){$event['deferred']=true;$event['defer_reason']='status_unavailable';}unset($event);}
 $events=array_values(array_filter($events,static fn($event)=>empty($event['archived'])));
 foreach($events as &$event)unset($event['key']);unset($event);
 echo json_encode(['success'=>true,'state'=>$state,'events'=>array_reverse($events)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){http_response_code(400);echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE);}
