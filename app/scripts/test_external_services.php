<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/lib/external_services.php';
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function fake_result(array $entries):callable{return static fn()=>['code'=>0,'stdout'=>json_encode(['entries'=>$entries])];}
$rows=[['id'=>'abcdefghijk','title'=>'Song','channel'=>'Artist','duration'=>120],['id'=>'lmnopqrstuv','title'=>'Song two']];
$items=sf_external_youtube_search('test',fake_result($rows));check(count($items)===2&&$items[0]['url']==='https://www.youtube.com/watch?v=abcdefghijk','stable search contract');
check(sf_external_youtube_search('test',fake_result([]))===[],'empty results are not provider failure');
$items=sf_external_youtube_search('test',fake_result([$rows[0],$rows[0],$rows[1]]));check(count($items)===2,'duplicate results removed');
foreach([
 static fn()=>['code'=>1,'stdout'=>''],
 static fn()=>['code'=>0,'stdout'=>'<html>changed</html>'],
 static fn()=>['code'=>0,'stdout'=>'{}'],
 fake_result([['id'=>'../../secret','title'=>'Bad']]),
 static function(){throw new RuntimeException('timeout');}
] as $i=>$runner){$failed=false;try{sf_external_youtube_search('test',$runner);}catch(Throwable $e){$failed=true;}check($failed,'provider failure contained '.$i);}
$start=microtime(true);$failed=false;try{yt_run([PHP_BINARY,'-r','usleep(3000000);'],1,1024);}catch(RuntimeException $e){$failed=true;}check($failed&&microtime(true)-$start<4,'process deadline enforced');
$failed=false;try{yt_run([PHP_BINARY,'-r','echo str_repeat("x",8192);'],3,1024);}catch(RuntimeException $e){$failed=true;}check($failed,'process output limit enforced');
check(cov_norm('ABBA')===cov_norm('abba'),'cover normalization');
check(is_string(lyrics_clean_title('ABBA - Dancing Queen')),'lyrics helper available');
echo "All isolated external service tests passed; no network or production data changes.\n";
