<?php
declare(strict_types=1);
require_once __DIR__.'/lib/processing_queue.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$account=lab_auth_current_account();if(!$account){http_response_code(401);echo json_encode(['error'=>'Sesión caducada']);exit;}
$id=(string)($_GET['id']??'');if(!preg_match('/^[a-f0-9]{32}$/',$id)){http_response_code(400);echo json_encode(['error'=>'ID inválido']);exit;}
$db=sfq_db();$q=$db->prepare('SELECT id,user_id,category,operation,label,state,stage,progress,error,result_json,created_at,started_at,finished_at FROM sf_processing_jobs WHERE id=?');$q->execute([$id]);$job=$q->fetch(PDO::FETCH_ASSOC);
if(!$job || ($job['user_id']!==$account['id'] && $account['role']!=='admin')){http_response_code(404);echo json_encode(['error'=>'Trabajo no encontrado']);exit;}
unset($job['user_id']);$job['result']=$job['result_json']?json_decode($job['result_json'],true):null;unset($job['result_json']);
if($job['state']==='queued'){$q=$db->prepare("SELECT COUNT(*) FROM sf_processing_jobs WHERE state='queued' AND created_at<?");$q->execute([$job['created_at']]);$job['ahead']=(int)$q->fetchColumn();}
$response=['success'=>true,'job'=>$job];
if(($_GET['include_queue']??'')==='1'){
 $known=array_values(array_filter(explode(',',(string)($_GET['queue_ids']??'')),static fn($v)=>preg_match('/^[a-f0-9]{32}$/D',$v)));$known=array_slice(array_unique($known),0,200);
 $where="(state IN ('queued','running')";$params=[];
 if($known){$where.=' OR id IN ('.implode(',',array_fill(0,count($known),'?')).')';$params=$known;}$where.=')';
 if($account['role']!=='admin'){$where.=' AND user_id=?';$params[]=$account['id'];}
 $q=$db->prepare('SELECT id,state,progress FROM sf_processing_jobs WHERE '.$where.' ORDER BY created_at DESC LIMIT 200');$q->execute($params);$response['queue']=$q->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
