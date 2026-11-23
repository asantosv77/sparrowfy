<?php declare(strict_types=1);
require __DIR__.'/lib.php';require_once __DIR__.'/lib/playback_diagnostics.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$settings=sf_playback_debug_settings(lab_load_config());
if($_SERVER['REQUEST_METHOD']==='GET'){echo json_encode($settings);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('{}');}
if(!$settings['enabled']||!$settings['record']){echo '{"accepted":0,"disabled":true}';exit;}
$raw=(string)($_POST['events']??'');if(strlen($raw)>20000){http_response_code(413);exit('{}');}
$events=json_decode($raw,true);if(!is_array($events)){http_response_code(400);exit('{}');}
$uid=(string)(lab_auth_current_account()['id']??'');
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
try{echo json_encode(['accepted'=>sf_playback_write_events(LAB_DATA.'/playback-diagnostics',$uid,$events,$settings['retention'])]);}catch(Throwable $e){http_response_code(503);echo '{"error":"Registro temporalmente no disponible"}';}
