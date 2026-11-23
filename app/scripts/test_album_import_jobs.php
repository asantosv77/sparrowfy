<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/album_import_jobs.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE sf_processing_jobs(id TEXT,user_id TEXT,album_id TEXT,operation TEXT,label TEXT,state TEXT,stage TEXT,progress INTEGER,created_at TEXT,error TEXT)');
$q=$db->prepare('INSERT INTO sf_processing_jobs(id,user_id,album_id,operation,label,state,stage,progress,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
for($i=0;$i<120;$i++)$q->execute(['maintenance'.$i,'u1','aaaaaaaaaaaaaaaa','loudness','Sonoridad','queued','En cola',0,'2020']);
check(sfq_album_import_jobs($db,'','u1',true)===[],'Maintenance-only queue must not appear as imports');
foreach([['upload','queued','u1','aaaaaaaaaaaaaaaa'],['youtube','running','u1','bbbbbbbbbbbbbbbb'],['client_hls','queued','u1','aaaaaaaaaaaaaaaa'],['upload','done','u1','aaaaaaaaaaaaaaaa'],['upload','failed','u1','aaaaaaaaaaaaaaaa'],['upload','queued','u2','aaaaaaaaaaaaaaaa']] as $i=>$r){[$op,$state,$user,$album]=$r;$q->execute(['import'.$i,$user,$album,$op,'Track',$state,'Processing',40,'2026']);}
check(count(sfq_album_import_jobs($db,'','u1',true))===3,'New form must recover own imports only, filter before LIMIT');
check(count(sfq_album_import_jobs($db,'aaaaaaaaaaaaaaaa','u1',false))===2,'Manager must see own album imports only');
check(count(sfq_album_import_jobs($db,'aaaaaaaaaaaaaaaa','u1',true))===3,'Admin retains album-wide import visibility');
check(sfq_album_import_jobs($db,"' OR 1=1 --",'u1',true)===[],'Album input remains bound');
echo "PASS import-only queue, maintenance exclusion, terminal states, ownership, album scope and bound inputs\n";

$done=sfq_album_import_finished($db,'','u1',true,['import3','import4','import5']);check(count($done)===2,'Only own known terminal imports');
$db->exec("UPDATE sf_processing_jobs SET state='failed' WHERE id='import5'");
check(count(sfq_album_import_finished($db,'aaaaaaaaaaaaaaaa','u1',false,['import3','import4','import5']))===2,'Manager must not read another owner result');
check(count(sfq_album_import_finished($db,'aaaaaaaaaaaaaaaa','u1',true,['import3','import4','import5']))===3,'Admin can read scoped known results');
check(sfq_album_import_finished($db,'','u1',true,[])===[],'No arbitrary history without known IDs');
echo "PASS terminal result scope, ownership and known-ID lookup\n";
