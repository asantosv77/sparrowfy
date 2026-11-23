<?php
require __DIR__.'/lib.php';lab_auth_require_album_manager();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
// A gestor can inspect only resources attached to their own processing job (admins may audit all).
$id=(string)($_GET['job_id']??'');
if($id!==''){
 if(!preg_match('/^[a-f0-9]{32}$/D',$id)){http_response_code(400);exit('{}');}
 $user=lab_auth_current_account();$db=lab_auth_pdo();
 $q=$db->prepare('SELECT user_id FROM sf_processing_jobs WHERE id=?');$q->execute([$id]);$owner=$q->fetchColumn();$q->closeCursor();
 if(!$owner||($owner!==$user['id']&&$user['role']!=='admin')){http_response_code(403);exit('{}');}
 if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
 $q=$db->prepare("SELECT t.request_id,r.device_name FROM sf_desktop_tasks t JOIN sf_worker_requests r ON r.id=t.request_id WHERE t.parent_id=? AND t.state IN ('leased','uploading') AND t.lease_until>=? ORDER BY t.created_at DESC LIMIT 1");$q->execute([$id,time()]);$task=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
 if($task){
  $metrics=json_decode((string)@file_get_contents(__DIR__.'/data/desktop-worker-jobs/node-'.$task['request_id'].'.json'),true)?:[];
  $stamp=(int)($metrics['updated_at']??0);$total=(float)($metrics['ram_total_mib']??0);$used=(float)($metrics['ram_used_mib']??0);
  echo json_encode(['updated_at'=>$stamp,'stale'=>time()-$stamp>45,'node_name'=>$task['device_name'].' · PC','node_type'=>'pc','storage'=>(json_decode((string)@file_get_contents(__DIR__.'/data/container-memory/latest.json'),true)['storage']??null),'containers'=>['www'=>['cpu_percent'=>$metrics['cpu_percent']??null,'percent'=>$total>0?100*$used/$total:null,'usage'=>number_format($used,0).' / '.number_format($total,0).' MiB (equipo completo)']]],JSON_UNESCAPED_UNICODE);exit;
 }
}
$p=__DIR__.'/data/container-memory/latest.json';$d=is_file($p)?json_decode((string)file_get_contents($p),true):null;
if(!is_array($d))$d=['updated_at'=>0,'containers'=>[]];
$d['node_name']='Raspberry · www';$d['node_type']='server';
$d['stale']=time()-(int)$d['updated_at']>20;
echo json_encode($d,JSON_UNESCAPED_UNICODE);
