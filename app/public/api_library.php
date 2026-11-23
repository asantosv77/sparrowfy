<?php
declare(strict_types=1);
require __DIR__.'/lib.php';lab_auth_require_album_manager();require_once __DIR__.'/lib/library_root.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);throw new RuntimeException('POST requerido');}
 lab_require_same_origin_post();if(!lab_auth_verify_csrf((string)($_POST['csrf_token']??''))){http_response_code(403);throw new RuntimeException('Recarga la pagina para renovar la sesion');}
 $action=(string)($_POST['action']??'');
 if($action==='prepare_upload'){echo json_encode(['success'=>true,'album_id'=>sf_ensure_loose()['id']]);exit;}
 if($action==='move_track'){require_once __DIR__.'/lib/library_move.php';sf_move_track((string)($_POST['source']??''),(string)($_POST['target']??''),(string)($_POST['track_id']??''));echo json_encode(['success'=>true]);exit;}
 throw new RuntimeException('Accion no disponible');
}catch(Throwable $e){if(http_response_code()<400)http_response_code(400);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
