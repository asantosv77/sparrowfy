<?php
declare(strict_types=1);
require __DIR__.'/lib.php';require_once __DIR__.'/lib/communication.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
$account=lab_auth_current_account();
try{
 $db=lab_auth_pdo();scom_schema($db);
 if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
  lab_require_same_origin_post();if(!lab_auth_verify_csrf((string)($_POST['csrf_token']??'')))throw new DomainException('Recarga la página para confirmar tu sesión.');
  $result=scom_mutate($db,$account,$_POST);
 }elseif(($_SERVER['REQUEST_METHOD']??'')==='GET'){
  $q=$db->prepare('SELECT notify FROM sf_com_preferences WHERE user_id=?');$q->execute([(string)$account['id']]);
  $v=$db->prepare('SELECT COALESCE(MAX(m.id),0) FROM sf_com_messages m JOIN sf_com_threads t ON t.id=m.thread_id WHERE m.author<>?'.(scom_admin($account)?'':' AND t.user_id=?'));$v->execute(scom_admin($account)?[(string)$account['id']]:[(string)$account['id'],(string)$account['id']]);
  $result=['revision'=>(int)$v->fetchColumn(),'success'=>true,'pending'=>scom_pending($db,(string)$account['id']),'unread'=>scom_unread($db,$account),'notify'=>scom_admin($account)&&(bool)$q->fetchColumn(),'admin'=>scom_admin($account)];
 }else{http_response_code(405);$result=['error'=>'Método no permitido.'];}
 echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(DomainException $e){http_response_code(403);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch(InvalidArgumentException $e){http_response_code(400);echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){http_response_code(503);echo json_encode(['error'=>'No se pudo guardar o consultar Comunicación. Conserva el mensaje y vuelve a intentarlo.'],JSON_UNESCAPED_UNICODE);}
