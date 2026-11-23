<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/lib/youtube_search_limits.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$session=[];for($i=0;$i<3;$i++)check(sf_youtube_search_admit($session,true,100.0)===0,'Batch burst');
check(sf_youtube_search_admit($session,true,100.1)===3,'Fourth request must wait');
check(sf_youtube_search_admit($session,true,103.1)===0,'Window expires');
$single=[];check(sf_youtube_search_admit($single,false,200.0)===0,'Single first');check(sf_youtube_search_admit($single,false,201.0)===2,'Single throttled');
$root=dirname(__DIR__).'/backups/youtube-slots-test-'.bin2hex(random_bytes(6));mkdir($root,02770,true);$locks=[];
for($i=0;$i<3;$i++){$locks[]=sf_youtube_search_slot($root);check(is_resource($locks[$i]),'Slot available');}
check(sf_youtube_search_slot($root)===null,'Fourth process must not enter');
flock($locks[1],LOCK_UN);fclose($locks[1]);$locks[1]=sf_youtube_search_slot($root);check(is_resource($locks[1]),'Released slot reused');
foreach($locks as $lock){flock($lock,LOCK_UN);fclose($lock);}
echo "PASS session limits, global three-slot cap and slot reuse\n";
