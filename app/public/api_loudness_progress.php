<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
lab_auth_require_admin();
require_once __DIR__.'/lib/processing_queue.php';
require_once __DIR__.'/lib/loudness_progress.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{$db=sfq_db();sflp_schema($db);$result=sflp_status($db,(string)lab_auth_current_account()['id']);if(session_status()===PHP_SESSION_ACTIVE)session_write_close();echo json_encode(['success'=>true,'progress'=>$result],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);}catch(Throwable $e){http_response_code(503);echo json_encode(['success'=>false,'error'=>'No se pudo consultar el progreso.']);}
