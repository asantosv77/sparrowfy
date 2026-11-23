<?php
declare(strict_types=1);if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/lib/processing_queue.php';require_once dirname(__DIR__).'/lib/mornye_requests.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);echo 'PASS '.$m.PHP_EOL;}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('CREATE TABLE sf_processing_jobs(id TEXT PRIMARY KEY,user_id TEXT,category TEXT,operation TEXT,album_id TEXT,label TEXT,payload TEXT,state TEXT,stage TEXT,progress INT,error TEXT,result_json TEXT,created_at TEXT,started_at TEXT,finished_at TEXT,updated_at TEXT)');sfmn_schema($db);
$albums=lab_list_albums();$album=null;foreach($albums as $a)if(($a['album_type']??'')!=='online_links'){$album=$a['id'];break;}if(!$album)throw new RuntimeException('No hay álbum para lectura de prueba');
$u=['id'=>'fixture-recovery'];$url='https://open.spotify.com/track/1234567890123456789012';$one=sfmn_enqueue($db,$u,$album,$url,'Fixture sin descarga');$two=sfmn_enqueue($db,$u,$album,$url,'Fixture sin descarga');check($one['job_id']===$two['job_id'],'repeat submission reuses job');check($db->query('SELECT count(*) FROM sf_processing_jobs')->fetchColumn()==1,'one durable job only');check($db->query('SELECT category FROM sf_processing_jobs')->fetchColumn()==='download','Mornye download separated from conversion');
$rejected=false;try{sfmn_enqueue($db,['id'=>'different'],$album,$url,'Fixture');}catch(RuntimeException $e){$rejected=true;}check($rejected,'another account cannot acquire local task');
$id=$one['job_id'];$sid=str_repeat('a',32);$db->prepare("UPDATE sf_processing_jobs SET state='done',payload=?,result_json=? WHERE id=?")->execute([json_encode(['mornye_client_source'=>$sid]),json_encode(['source_id'=>$sid]),$id]);$batch=['id'=>str_repeat('b',32),'album_id'=>$album,'local_mode'=>1,'tracks'=>json_encode([['job_id'=>$id,'title'=>'Fixture']])];$status=sfmn_batch_status($db,$batch);check($status['jobs'][0]['state']==='awaiting_client'&&!$status['jobs'][0]['finished'],'source-ready is not published');
$final=sfq_add($db,$u['id'],'conversion','client_hls',$album,'Fixture',['mornye_parent'=>$id]);$status=sfmn_batch_status($db,$batch);check($status['jobs'][0]['state']==='queued','resumed conversion linked to source');$db->prepare("UPDATE sf_processing_jobs SET state='done' WHERE id=?")->execute([$final]);check(sfmn_batch_status($db,$batch)['jobs'][0]['finished'],'published conversion completes batch');
$batch['tracks']=json_encode([['title'=>'Pending','job_id'=>'']]);check(sfmn_batch_status($db,$batch)['jobs'][0]['state']==='awaiting_submission','partially submitted batch recoverable');echo "MORNYE_RECOVERY_TESTS_OK\n";

define('SFQ_FUNCTIONS_ONLY',true);require __DIR__.'/processing_worker.php';$download=sfq_add($db,$u['id'],'download','mornye',$album,'Network fixture',[]);$cpu=sfq_add($db,$u['id'],'conversion','upload',$album,'CPU fixture',[]);check(sfq_claim($db,'conversion')['id']===$cpu,'CPU claim ignores waiting download');check(sfq_claim($db,'download')['id']===$download,'network claim uses separate queue');

$db->prepare("UPDATE sf_processing_jobs SET state='failed' WHERE id=?")->execute([$id]);
$resumed=sfmn_enqueue($db,$u,$album,$url,'Nombre corregido',false);
check($resumed['job_id']===$id&&$resumed['state']==='queued','explicit submit requeues failed job without duplicate');
$q=$db->prepare('SELECT payload FROM sf_processing_jobs WHERE id=?');$q->execute([$id]);$p=json_decode($q->fetchColumn(),true);
check($p['filename']==='Nombre corregido'&&empty($p['mornye_client_source']),'resubmission preserves chosen name and server mode');
check(!sfmn_resume_failed_source($db,$u,$id,false,'Duplicate'),'queued task not restarted');
$db->prepare("UPDATE sf_processing_jobs SET state='failed' WHERE id=?")->execute([$id]);
check(!sfmn_resume_failed_source($db,['id'=>'different'],$id,false,'Unauthorized'),'other account cannot reactivate failed job');
