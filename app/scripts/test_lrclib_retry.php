<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$s=file_get_contents(dirname(__DIR__).'/api_lyrics.php');
$a=strpos($s,'function lyrics_norm(');$b=strpos($s,'function lyrics_http_get(',$a);eval(substr($s,$a,$b-$a));
$a=strpos($s,'function lyrics_clean_title(');$b=strpos($s,'function lyrics_from_ovh(',$a);$code=substr($s,$a,$b-$a);eval($code);
$calls=0;$scenario='retry';
function lyrics_http_get(string $url,array $headers=[],int $timeout=12):array{
 global $calls,$scenario;$calls++;
 if($scenario==='retry'&&$calls<3)return ['code'=>503,'body'=>''];
 $sync=$scenario==='plain_first'&&$calls===1?'':'[00:01.00]fixture';
 return ['code'=>200,'body'=>json_encode([['artistName'=>'Fixture Artist','trackName'=>'Fixture Song','duration'=>180,'syncedLyrics'=>$sync,'plainLyrics'=>'fixture']])];
}
$r=lyrics_from_lrclib('Fixture Artist','Fixture Song',180);if($calls!==3||empty($r['synced']))throw new RuntimeException('Transient retry failed');
$scenario='plain_first';$calls=0;$r=lyrics_from_lrclib('Fixture Artist','Fixture Song',180);if($calls<2||empty($r['synced']))throw new RuntimeException('Stopped at plain result');
if(lyrics_clean_title('01 - Fixture Song (Official Audio).mp3')!=='Fixture Song')throw new RuntimeException('Title cleanup failed');
echo "PASS: two 503 retries, synchronized result after plain match, title cleanup\n";
