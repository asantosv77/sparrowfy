<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
require_once __DIR__.'/lib/user_preferences.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$account=lab_auth_current_account();
if(!is_array($account)){http_response_code(401);echo json_encode(['error'=>'Inicia sesión.']);exit;}
try {
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  if(!lab_auth_verify_csrf(isset($_POST['csrf_token'])?(string)$_POST['csrf_token']:null)){http_response_code(403);echo json_encode(['error'=>'Recarga la página para guardar tu preferencia.']);exit;}
  if(!in_array($_POST['show_tips']??null,['0','1'],true)){http_response_code(400);echo json_encode(['error'=>'Preferencia inválida.']);exit;}
  lab_user_save_tips((string)$account['id'],$_POST['show_tips']==='1');
 }elseif(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);exit;}
 echo json_encode(['show_tips'=>lab_user_show_tips((string)$account['id']),'user_id'=>(string)$account['id'],'can_manage'=>lab_auth_can_manage_albums(),'is_admin'=>($account['role']??'')==='admin']);
}catch(Throwable $e){error_log('SparrowFy tips preferences: '.get_class($e));http_response_code(500);echo json_encode(['error'=>'No se pudo guardar la preferencia de tips.']);}
