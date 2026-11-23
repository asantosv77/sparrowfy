<?php
require __DIR__.'/lib.php';require_once __DIR__.'/lib/usage.php';require_once __DIR__.'/lib/usage_report.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
 $p=lab_auth_pdo();sf_usage_schema($p);$account=lab_auth_current_account();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $kind=(string)($_POST['kind']??'web');if(!in_array($kind,['web','playing','paused'],true))throw new InvalidArgumentException();
  if(time()-(int)($_SESSION['usage_touch']??0)>=5||$kind==='paused'){
   sf_usage_heartbeat($p,(string)$account['id'],(string)($_SESSION['device_id']??''),$kind);$_SESSION['usage_touch']=time();
  }
  echo '{"ok":true}';exit;
 }
 $scope=sf_usage_scope($account,(string)($_GET['scope']??''));
 $users=[];if(lab_auth_is_admin())$users=$p->query('SELECT id,username FROM users ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);
 if($scope!==null){$q=$p->prepare('SELECT 1 FROM users WHERE id=?');$q->execute([$scope]);if(!$q->fetchColumn())throw new InvalidArgumentException();}
 echo json_encode(['report'=>sf_usage_report($p,$scope),'users'=>$users],JSON_THROW_ON_ERROR);
}catch(Throwable $e){error_log('Usage report: '.$e->getMessage());http_response_code(403);echo json_encode(['error'=>'No se pudo consultar el consumo.']);}
