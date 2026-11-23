<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/lib/external_mornye.php';
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name."\n";}
$id='1234567890123456789012';$url='https://open.spotify.com/track/'.$id;
$rows=sf_external_mornye_search('fixture',static fn()=>['tracks'=>[['id'=>$id,'title'=>'Song','artists'=>'Artist','duration_ms'=>215000],['id'=>$id,'title'=>'Duplicate'],['id'=>'../bad','title'=>'Bad']]]);
check(count($rows)===1&&$rows[0]['duration']===215&&$rows[0]['url']===$url,'normalized search and duplicate filtering');
check(sf_external_mornye_search('fixture',static fn()=>['tracks'=>[]])===[],'empty search is not service failure');
foreach(['http://127.0.0.1/','https://open.spotify.com.evil.test/track/'.$id,'https://open.spotify.com/album/'.$id] as $bad){$rejected=false;try{sf_external_mornye_url($bad);}catch(Throwable $e){$rejected=true;}check($rejected,'unsafe or non-track URL rejected');}
$job=sf_external_mornye_start($url,'/inbox/sf-'.str_repeat('a',32),static function($op,$data)use($url){check($op==='start'&&$data['url']===$url,'download request retains selected URL');return ['id'=>'job-fixture'];});check($job['id']==='job-fixture','remote job ID');
$rejected=false;try{sf_external_mornye_start($url,'/inbox/../other',static fn()=>[]);}catch(Throwable $e){$rejected=true;}check($rejected,'output path boundary');
check(sf_external_mornye_status('job-fixture',static fn()=>['status'=>'paused','error'=>'Manual verification'])['status']==='paused','manual intervention preserved');
$rejected=false;try{sf_external_mornye_status('job-fixture',static fn()=>['status'=>'unknown']);}catch(Throwable $e){$rejected=true;}check($rejected,'schema drift rejected');
echo "MORNYE_IMPORT_TESTS_OK\n";
