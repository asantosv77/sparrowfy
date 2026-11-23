<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
lab_auth_require_album_manager();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST');echo json_encode(['error'=>'POST requerido']);exit;}
try{
 lab_require_same_origin_post();if(!lab_auth_verify_csrf((string)($_POST['csrf_token']??'')))throw new InvalidArgumentException('La sesión del formulario caducó. Recarga el álbum.');
 require_once __DIR__.'/lib/album_track_order.php';
 $order=json_decode((string)($_POST['order']??''),true,32,JSON_THROW_ON_ERROR);$expected=json_decode((string)($_POST['expected_order']??''),true,32,JSON_THROW_ON_ERROR);
 if(!is_array($order)||!is_array($expected))throw new InvalidArgumentException('Orden inválido');
 $ids=sf_album_order_save((string)($_POST['album_id']??''),$order,$expected);echo json_encode(['success'=>true,'order'=>$ids]);
}catch(Throwable $e){http_response_code($e instanceof RuntimeException?409:400);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
