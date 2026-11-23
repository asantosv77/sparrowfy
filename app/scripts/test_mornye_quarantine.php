<?php
declare(strict_types=1);if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/lib/processing_queue.php';require dirname(__DIR__).'/lib/mornye_recovery.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);echo 'PASS '.$m.PHP_EOL;}
$root=dirname(__DIR__).'/data/fixture-quarantine-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/receipts',0700);
$db=new PDO('sqlite::memory:');$db->exec('CREATE TABLE sf_processing_jobs(id TEXT,state TEXT,updated_at TEXT,payload TEXT)');
$ids=[];$events=[];
try{
 foreach(range(1,6) as $i){$id=str_pad((string)$i,32,'0',STR_PAD_LEFT);$ids[$i]=$id;mkdir($root.'/sf-'.$id,0700);file_put_contents($root.'/sf-'.$id.'/fixture.txt','disposable fixture');$db->prepare('INSERT INTO sf_processing_jobs VALUES(?,?,?,?)')->execute([$id,$i===2?'running':'failed',gmdate('Y-m-d H:i:s',time()-($i===5?100:90000)),'{}']);file_put_contents($root.'/receipts/mornye-'.$id.'.json',json_encode(['remote_id'=>'remote-'.$i,'submitting'=>$i===6]));}
 $db->prepare('INSERT INTO sf_processing_jobs VALUES(?,?,?,?)')->execute(['child','queued',gmdate('c'),json_encode(['mornye_parent'=>$ids[3]])]);
 $remote=static function($id){if($id==='remote-4')throw new RuntimeException('network down');return ['status'=>'failed'];};
 sfmn_quarantine_scan($db,$root,$root.'/receipts',$remote,function($c,$m,$l)use(&$events){$events[]=$c;},$root.'/cursor.json');
 check(!is_dir($root.'/sf-'.$ids[1])&&count(glob($root.'/.quarantine/sf-'.$ids[1].'-*'))===1,'expired terminal fixture moved to quarantine');
 foreach([2=>'running task',3=>'active publication',4=>'unknown remote status',5=>'younger than 24 hours',6=>'uncertain submission'] as $i=>$label)check(is_file($root.'/sf-'.$ids[$i].'/fixture.txt'),'preserves '.$label);
 check(count($events)===1,'one quarantine notification');
 check(is_file($root.'/cursor.json'),'scan cursor persists');
 check(count(glob($root.'/.quarantine/*/fixture.txt'))===1,'quarantine preserves bytes');
 $race=function($remote)use($db,$ids){$db->prepare("UPDATE sf_processing_jobs SET state='queued' WHERE id=?")->execute([$ids[4]]);return ['status'=>'failed'];};
 sfmn_quarantine_scan($db,$root,$root.'/receipts',$race,static function(){},$root.'/cursor.json');
 check(is_file($root.'/sf-'.$ids[4].'/fixture.txt'),'requeue during remote check cancels quarantine');
}finally{
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);
}
