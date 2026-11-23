<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');header('Pragma: no-cache');header('Vary: Cookie');header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);echo json_encode(['error'=>'Método no permitido']);exit;}
if(($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site'){http_response_code(403);echo json_encode(['error'=>'Origen no permitido']);exit;}
$account=lab_auth_current_account();
$anonymous=($_GET['anonymous']??'')==='1' && empty($_SESSION['user_id']);
if(!$account&&!$anonymous){http_response_code(401);echo json_encode(['error'=>'No se pudo verificar tu sesión. Vuelve a iniciar sesión si es necesario.','code'=>'session_unavailable']);exit;}
$token=lab_auth_csrf_token();$uid=$account?(string)$account['id']:'';
session_write_close();
echo json_encode(['csrf_token'=>$token,'account_id'=>$uid,'authenticated'=>$account!==null],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
