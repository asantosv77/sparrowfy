<?php
declare(strict_types=1);
require __DIR__.'/lib.php';lab_auth_require_admin();
require __DIR__.'/lib/external_ai.php';require __DIR__.'/lib/external_validation.php';require __DIR__.'/lib/external_models.php';require __DIR__.'/lib/external_review.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
function sfem_reply(array $d,int $status=200):never{http_response_code($status);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function sfem_jobs():array{$jobs=[];foreach(glob(sfem_dir().'/job-*.json')?:[] as $file)$jobs[]=sfem_read_json($file);usort($jobs,static fn($a,$b)=>strcmp($b['id']??'',$a['id']??''));return array_slice($jobs,0,20);}
try{
 $action=(string)($_POST['action']??$_GET['action']??'status');
 if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
  if($action==='notifications'){$viewer=lab_auth_current_account();$keys=array_flip(['id','state','stage','created_at','finished_at','result','summary','error','model','accounting','balance','review_state','reviewed_at']);$jobs=array_map(static fn($j)=>array_intersect_key($j,$keys),sfem_jobs());sfem_reply(['success'=>true,'viewer_id'=>(string)$viewer['id'],'jobs'=>$jobs]);}
  if($action==='status')sfem_reply(['success'=>true,'jobs'=>sfem_jobs(),'schedule'=>sfem_schedule_view(),'heartbeat'=>sfem_read_json(sfem_dir().'/heartbeat.json')]);
  if($action==='settings')sfem_reply(['success'=>true,'settings'=>sfem_public_settings(),'files'=>sfem_files(),'versions'=>sfem_versions()]);
  if($action==='source'){$file=(string)($_GET['file']??sfem_files()[0]);sfem_file($file);$bundle=sfem_bundle();sfem_reply(['success'=>true,'file'=>$file,'source'=>$bundle['sources'][$file],'hashes'=>$bundle['hashes']]);}
  if($action==='skill')sfem_reply(['success'=>true,'source'=>(string)file_get_contents(__DIR__.'/scripts/skills/sparrowfy-external-maintenance/SKILL.md')]);
  if($action==='proposal'){$id=(string)($_GET['id']??'');if(!preg_match('/^\d{8}-\d{6}-[a-f0-9]{10}$/D',$id))throw new RuntimeException('Revisión inválida.');sfem_reply(['success'=>true,'data'=>sfem_read_json(sfem_dir().'/proposal-'.$id.'.json')]);}
  throw new RuntimeException('Acción de lectura inválida.');
 }
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')sfem_reply(['success'=>false,'error'=>'POST requerido.'],405);
 lab_require_same_origin_post();if(!lab_auth_verify_csrf(isset($_POST['csrf_token'])?(string)$_POST['csrf_token']:null))sfem_reply(['success'=>false,'error'=>'La sesión expiró. Recarga el panel.'],403);
 $u=lab_auth_current_account();$actor=(string)($u['username']??'admin');
 if($action==='approve'||$action==='rollback'){if(session_status()===PHP_SESSION_ACTIVE)session_write_close();$review=sfem_review_update((string)($_POST['job_id']??''),$action,$actor);sfem_reply(['success'=>true,'review'=>$review,'versions'=>sfem_versions()]);}
 if($action==='models'){$settings=sfem_settings();$id=(string)($_POST['provider']??$settings['provider']);$provider=$settings['providers'][$id]??null;if(!is_array($provider))throw new RuntimeException('Proveedor no encontrado.');if(session_status()===PHP_SESSION_ACTIVE)session_write_close();sfem_reply(['success'=>true,'catalog'=>sfem_models($provider,!empty($_POST['force']))]);}
 if($action==='settings'){sfem_save_settings($_POST);sfem_reply(['success'=>true,'settings'=>sfem_public_settings()]);}
 if($action==='save'||$action==='restore'){
  $encoded=(string)($_POST['hashes']??'');if(strlen($encoded)>5000)throw new RuntimeException('Versión de código inválida.');$hashes=json_decode($encoded,true,8,JSON_THROW_ON_ERROR);if(!is_array($hashes))throw new RuntimeException('Falta la versión base.');
  if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
  $changes=$action==='restore'?sfem_version_sources((string)($_POST['version']??'')):[(string)($_POST['file']??'')=>(string)($_POST['source']??'')];
  $result=sfem_apply($changes,$hashes,$actor,$action==='restore'?'Restauración de versión '.(string)$_POST['version']:'Edición manual del administrador');sfem_reply(['success'=>true,'publication'=>$result,'versions'=>sfem_versions()]);
 }
 if($action==='process'){if(sfem_pending_review())throw new RuntimeException('Hay una actualización pendiente: apruébala o reviértela antes de iniciar otra revisión.');
  $s=sfem_settings();$p=$s['providers'][$s['provider']]??[];if(empty($p['token']))throw new RuntimeException('Guarda primero el proveedor y su token de API.');
  $lock=fopen(sfem_dir().'/enqueue.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Otra solicitud está entrando.');
  try{foreach(sfem_jobs() as $j)if(in_array($j['state']??'',['queued','running'],true))sfem_reply(['success'=>true,'job_id'=>$j['id'],'existing'=>true]);$id=gmdate('Ymd-His').'-'.bin2hex(random_bytes(5));sfem_json(sfem_dir().'/job-'.$id.'.json',['id'=>$id,'state'=>'queued','trigger'=>'manual','actor'=>$actor,'provider'=>$s['provider'],'created_at'=>gmdate('c')]);}finally{flock($lock,LOCK_UN);fclose($lock);}
  sfem_reply(['success'=>true,'job_id'=>$id]);
 }
 throw new RuntimeException('Acción inválida.');
}catch(Throwable $e){sfem_reply(['success'=>false,'error'=>$e instanceof JsonException?'Datos JSON inválidos.':$e->getMessage()],400);}
