<?php
declare(strict_types=1);if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/lib/mornye_event_resolution.php';
function check($v,$name){if(!$v)throw new RuntimeException($name);echo 'PASS '.$name.PHP_EOL;}
$db=new PDO('sqlite::memory:');$db->exec('CREATE TABLE sf_processing_jobs(id TEXT,operation TEXT,album_id TEXT,payload TEXT,state TEXT,result_json TEXT,created_at TEXT,finished_at TEXT)');
$dir=dirname(__DIR__).'/data/fixture-events-'.bin2hex(random_bytes(6));mkdir($dir,0700);$a=str_repeat('a',32);$b=str_repeat('b',32);$c=str_repeat('c',32);$d=str_repeat('d',32);$new=str_repeat('e',32);
$add=function($id,$op,$payload,$state,$track=false,$created='2026-11-13T10:00:00Z')use($db){$db->prepare('INSERT INTO sf_processing_jobs VALUES(?,?,?,?,?,?,?,?)')->execute([$id,$op,'album',json_encode($payload),$state,$track?json_encode(['track'=>['id'=>'track','hls'=>'hls_out/master.m3u8']]):'{}',$created,'2026-11-13T11:00:00Z']);};
$add($a,'mornye',['url'=>'song-a'],'done',true);$add($b,'mornye',['url'=>'song-b'],'failed');$add($c,'mornye',['url'=>'song-c','mornye_client_source'=>'local'],'done');$add($d,'mornye',['url'=>'song-a'],'failed');$add($new,'mornye',['url'=>'song-a'],'failed',false,'2026-11-13T12:00:00Z');
file_put_contents($dir.'/mornye-'.$a.'.json',json_encode(['remote_id'=>'job-final','history'=>[['id'=>'job-failed']]]));
$events=[['code'=>'import.retry.'.$a,'read'=>false],['code'=>'download.failed.job-failed'],['code'=>'import.failed.'.$b],['code'=>'import.failed.'.$c],['code'=>'import.failed.'.$d,'read'=>true],['code'=>'import.failed.'.$new],['code'=>'inbox.cleaned.'.$a]];
try{
 $x=sfmn_resolved_events($events,$db,$dir);
 check($x[0]['resolved']&&$x[1]['resolved'],'retry and failed remote attempt resolve together');
 check(!$x[2]['resolved'],'unrelated failure stays pending');
 check(!$x[3]['resolved'],'local source ready is not completed publication');
 check($x[4]['resolved']&&$x[4]['read'],'earlier equivalent request resolves without changing manual acknowledgement');
 check(!$x[5]['resolved'],'later failure not hidden by earlier success');
 check(!$x[6]['resolved'],'success notice remains normal');
 check(count($x)===count($events)&&$x[0]['code']===$events[0]['code'],'history retained');
 $add('child','client_hls',['mornye_parent'=>$c],'done',true);$x=sfmn_resolved_events($events,$db,$dir);check($x[3]['resolved'],'local source resolves after final publication');
}finally{foreach(glob($dir.'/*') as $p)unlink($p);rmdir($dir);}
