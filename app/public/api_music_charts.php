<?php
declare(strict_types=1);
require __DIR__.'/lib/processing_queue.php';require_once __DIR__.'/lib/charts_view.php';require_once __DIR__.'/lib/spotify_charts.php';require_once __DIR__.'/lib/charts_collections.php';
header('Cache-Control: no-store, private');
try{
 if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
  $requestedAction=(string)($_POST['action']??'');if(in_array($requestedAction,['copy','copy_preview'],true))lab_auth_require_album_manager();else lab_auth_require_admin();lab_require_same_origin_post();if(!lab_auth_verify_csrf((string)($_POST['csrf_token']??'')))throw new RuntimeException('Sesión caducada. Recarga antes de continuar');
  $action=(string)($_POST['action']??'');$db=sfq_db();sfc_schema($db);
  if($action==='copy_preview'){$selection=sfcc_selection($db,(string)($_POST['chart']??''));$result=['success'=>true,'title'=>$selection['name'],'available'=>count($selection['ids']),'total'=>$selection['total'],'signature'=>$selection['signature']];}
  elseif($action==='copy'){$result=['success'=>true]+sfcc_copy($db,lab_auth_current_account(),(string)($_POST['chart']??''),(string)($_POST['request_key']??''),(string)($_POST['signature']??''));}
  elseif($action==='refresh'){sfc_json(sfc_root().'/refresh.json',['requested_at'=>gmdate('c')]);$result=['success'=>true,'message'=>'Actualización solicitada. El dispatcher la recogerá en menos de un minuto.'];}
  elseif($action==='retry'){throw new RuntimeException('Los TOP se procesan en modo mejor esfuerzo; las pendientes se evaluarán en la siguiente actualización.');}
  elseif($action==='spotify_login'){$login=sfc_spotify_login_start();$result=['success'=>true,'message'=>$login['message']??'Inicia sesión en Spotify dentro del navegador y después pulsa «Comprobar conexión».','login'=>$login];}
  elseif($action==='spotify_test'){$test=sfc_spotify_test();$result=['success'=>true,'message'=>$test['message']??'Conexión correcta con Spotify Charts.','status'=>sfc_spotify_status()];}
  elseif($action==='spotify_disconnect'){$off=sfc_spotify_disconnect();$result=['success'=>true,'message'=>$off['message']??'Sesión de Spotify Charts eliminada de este equipo.'];}
  elseif($action==='spotify_status'){$result=['success'=>true,'status'=>sfc_spotify_status()];}
  else throw new RuntimeException('Acción no permitida');
  header('Content-Type: application/json; charset=utf-8');echo json_encode($result,JSON_UNESCAPED_UNICODE);exit;
 }
 if(($_GET['spotify_status']??'')==='1'){lab_auth_require_admin();header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>true,'status'=>sfc_spotify_status()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
 if(($_GET['fragment']??'')==='1'){header('Content-Type: text/html; charset=utf-8');sfc_home();exit;}
 $db=lab_auth_pdo();$settings=sfc_settings();$charts=$db->query('SELECT * FROM sf_music_charts WHERE enabled=1 ORDER BY chart_key')->fetchAll(PDO::FETCH_ASSOC);foreach($charts as &$chart)$chart['items']=sfc_entry_rows($db,$chart['chart_key']);unset($chart);
 $file=sfc_root().'/status.json';$status=is_file($file)?json_decode((string)file_get_contents($file),true):null;
 header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>true,'charts'=>$charts,'status'=>$status],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(400);header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);}
