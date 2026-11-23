<?php
declare(strict_types=1);require dirname(__DIR__).'/lib/loudness_progress.php';
function ck(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('CREATE TABLE sf_processing_jobs(id TEXT,operation TEXT,state TEXT,label TEXT,stage TEXT,progress INTEGER,created_at TEXT,error TEXT,started_at TEXT,finished_at TEXT)');sflp_schema($db);
ck(sflp_status($db,'u')['total']===0,'Empty status');
$db->exec("INSERT INTO sf_processing_jobs(id,operation,state,label,stage,progress,created_at) VALUES('a','loudness','queued','A','En cola',0,'2026'),('b','loudness','running','B','Analizando',30,'2026'),('c','upload','running','C','Importando',50,'2026')");
sflp_capture($db,'u');$s=sflp_status($db,'u');ck($s['total']===2&&$s['percent']===15.0&&$s['current']['label']==='B','Separate stable batch and progress');$id=$s['batch_id'];
sflp_capture($db,'u');ck(sflp_status($db,'u')['batch_id']===$id,'Reopen must preserve batch');
$db->exec("UPDATE sf_processing_jobs SET state='done',progress=100 WHERE id='a'");$s=sflp_status($db,'u');ck($s['total']===2&&$s['percent']===65.0,'Completed jobs remain in denominator');
$db->exec("UPDATE sf_processing_jobs SET state='failed' WHERE id='b'");$s=sflp_status($db,'u');ck(!$s['active']&&$s['failed']===1&&$s['percent']===100.0,'Failed is terminal, not successful');sflp_capture($db,'u');ck(sflp_status($db,'u')['batch_id']===$id,'Completed result survives reopening');
ck(sflp_status($db,'other')['total']===0,'Owner separation');
$db->exec("INSERT INTO sf_processing_jobs(id,operation,state,label,stage,progress,created_at) VALUES('d','loudness','queued','D','En cola',0,'2027')");sflp_capture($db,'u');$s=sflp_status($db,'u');ck($s['batch_id']!==$id&&$s['total']===1,'New active set creates separate batch');
$db->exec("DELETE FROM sf_processing_jobs WHERE id='d'");$s=sflp_status($db,'u');ck(!$s['active']&&$s['missing']===1,'Missing records reported honestly');
ck(sflp_status($db,"' OR 1=1 --")['total']===0,'Bound owner');
echo "PASS sonority separation, stable batches, restart, failures, missing jobs and ownership\n";
