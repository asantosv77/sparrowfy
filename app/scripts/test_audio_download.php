<?php
// Isolated DB and generated audio only. Never touches the live catalog or accounts.
$root=dirname(__DIR__).'/data/test-audio-download-'.bin2hex(random_bytes(5));mkdir($root);define('LAB_DATA',$root);
$GLOBALS['testdb']=new PDO('sqlite::memory:');function lab_auth_pdo(){return $GLOBALS['testdb'];}
$code=file_get_contents(dirname(__DIR__).'/lib/audio_download.php');$code=preg_replace('/^require_once .*;$/m','',$code);eval(substr($code,5));
function check($v,$message){if(!$v)throw new RuntimeException($message);}
function runCmd($cmd){$p=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as $f)fclose($f);if(proc_close($p)!==0)throw new RuntimeException($err);return $out;}
function rejected($fn){try{$fn();}catch(RuntimeException){return;}throw new RuntimeException('Unsafe manifest accepted');}
function eraseFixture($p){foreach(scandir($p) as $n){if($n==='.'||$n==='..')continue;$f=$p.'/'.$n;if(is_dir($f)&&!is_link($f))eraseFixture($f);else unlink($f);}rmdir($p);}
try{
 $p=lab_auth_pdo();$p->exec("CREATE TABLE users(id TEXT PRIMARY KEY,is_active INTEGER,role TEXT);INSERT INTO users VALUES('a',1,'admin'),('b',1,'user'),('c',0,'user')");sfd_db();
 check(!sfd_allowed('a')&&!sfd_allowed('b'),'Default permission must be off, including admins');
 $p->exec("INSERT INTO sf_audio_download_permissions VALUES('a',1),('b',1),('c',1)");check(sfd_allowed('a')&&sfd_allowed('b')&&!sfd_allowed('c'),'Active-account permission');$p->exec("UPDATE sf_audio_download_permissions SET enabled=0 WHERE user_id='b'");check(!sfd_allowed('b'),'Revocation');
 foreach([64,192] as $rate){mkdir($root.'/'.$rate);runCmd(['ffmpeg','-nostdin','-loglevel','error','-f','lavfi','-i','sine=frequency=440:duration=3','-ac','2','-c:a','aac','-b:a',$rate.'k','-f','hls','-hls_time','1','-hls_playlist_type','vod',$root.'/'.$rate.'/index.m3u8']);}
 $master=$root.'/master.m3u8';file_put_contents($master,"#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=64000\n64/index.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=192000\n192/index.m3u8\n");$m=sfd_read_manifest($master);check($m['bandwidth']===192000&&str_contains($m['body'],'/192/'),'Best rendition not selected');
 foreach(['https://example.com/audio.ts','../outside.ts','/etc/passwd'] as $bad){file_put_contents($root.'/bad.m3u8',"#EXTM3U\n#EXTINF:1,\n".$bad."\n#EXT-X-ENDLIST\n");rejected(fn()=>sfd_read_manifest($root.'/bad.m3u8'));}
 file_put_contents($root.'/bad.m3u8',"#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"https://example.com/key\"\n#EXT-X-ENDLIST\n");rejected(fn()=>sfd_read_manifest($root.'/bad.m3u8'));
 foreach(['m4a','mp3'] as $i=>$format){$id=str_repeat((string)($i+1),32);$r=sfd_encode(['id'=>'fixture','name'=>'Canción de prueba','duration'=>3],$m,$format,$id);$out=sfd_root().'/'.$id.'.'.$format;$probe=json_decode(runCmd(['ffprobe','-v','error','-show_entries','stream=codec_name','-show_entries','format=duration','-of','json',$out]),true);check($probe['streams'][0]['codec_name']===($format==='mp3'?'mp3':'aac'),'Unexpected codec');check(abs((float)$probe['format']['duration']-3)<.3,'Duration mismatch');check(!is_file(sfd_root().'/'.$id.'.part'),'Temporary output leaked');}
 echo "PASS: defaults, active accounts, revocation, highest rendition, unsafe paths, real M4A/MP3 codecs and durations, temporary cleanup\n";
}finally{eraseFixture($root);}
