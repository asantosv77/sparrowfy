<?php
declare(strict_types=1);
require_once __DIR__.'/mobile_sessions.php';
/** Shared Bearer HLS delivery. Paths derive from deployment, never /audio. */
function sf_mobile_hls_error(int $code):never{http_response_code($code);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>match($code){401=>'Token inválido o expirado',404=>'Audio no encontrado',405=>'Método no permitido',416=>'Rango no válido',default=>'Solicitud HLS no válida'}]);exit;}
function sf_mobile_hls_account(PDO $p):string{
 $h=(string)($_SERVER['HTTP_AUTHORIZATION']??'');if(!$h&&function_exists('getallheaders'))foreach(getallheaders() as $k=>$v)if(strtolower($k)==='authorization')$h=(string)$v;
 if(!preg_match('/^Bearer\s+([a-f0-9]{64})$/iD',$h,$m))sf_mobile_hls_error(401);
 $q=$p->prepare('SELECT u.id FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.refresh_token_hash=? AND s.revoked_at IS NULL AND s.expires_at>? AND u.is_active=1 LIMIT 1');$q->execute([hash('sha256',strtolower($m[1])),gmdate('c')]);$uid=$q->fetchColumn();if(!$uid)sf_mobile_hls_error(401);return (string)$uid;
}
function sf_mobile_hls_target(string $base,string $uri,string $trackRoot):string{
 if($uri===''||str_contains($uri,"\0")||str_contains($uri,'\\')||preg_match('~^(?:/|[a-z][a-z0-9+.-]*:)~i',$uri)||str_contains($uri,'?')||str_contains($uri,'#'))throw new RuntimeException('Invalid HLS URI');
 $file=realpath($base.'/'.$uri);if(!$file||!is_file($file)||!str_starts_with($file,$trackRoot.DIRECTORY_SEPARATOR)||!in_array(strtolower(pathinfo($file,PATHINFO_EXTENSION)),['m3u8','ts','aac','m4s','mp4'],true))throw new RuntimeException('Invalid HLS target');return $file;
}
function sf_mobile_hls_rewrite(string $body,string $manifest,string $trackRoot,string $hlsRoot,string $tid,string $scriptDir):string{
 $rewrite=static function(string $uri)use($manifest,$trackRoot,$hlsRoot,$tid,$scriptDir):string{$file=sf_mobile_hls_target(dirname($manifest),$uri,$trackRoot);return $scriptDir.'/segment.php?track_id='.rawurlencode($tid).'&file='.rawurlencode(substr($file,strlen($hlsRoot)+1));};
 $lines=preg_split('/\r\n|\n|\r/',$body);foreach($lines as &$line){$trim=trim($line);if($trim==='')continue;if($trim[0]!=='#')$line=$rewrite($trim);else $line=preg_replace_callback('/URI="([^"\r\n]+)"/',static fn($m)=>'URI="'.$rewrite($m[1]).'"',$line);}unset($line);return implode("\n",$lines);
}
function sf_mobile_hls_serve(bool $segment):never{
 header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
 $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));if(!in_array($method,['GET','HEAD'],true)){header('Allow: GET, HEAD');sf_mobile_hls_error(405);}
 $p=lab_auth_pdo();sf_mobile_sessions_schema($p);$uid=sf_mobile_hls_account($p);if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
 $tid=(string)($_GET['track_id']??'');if(!preg_match('/^[a-f0-9]{16}$/D',$tid))sf_mobile_hls_error(400);
 $q=$p->prepare('SELECT hls_path FROM tracks WHERE id=?');$q->execute([$tid]);$rel=$q->fetchColumn();$hlsRoot=realpath(dirname(__DIR__).'/hls_out');
 $manifest=is_string($rel)?realpath(dirname(__DIR__).'/'.$rel):false;if(!$hlsRoot||!$manifest||!is_file($manifest)||!str_starts_with($manifest,$hlsRoot.DIRECTORY_SEPARATOR))sf_mobile_hls_error(404);
 $trackRoot=dirname($manifest);$file=$manifest;
 if($segment){try{$file=sf_mobile_hls_target($hlsRoot,(string)($_GET['file']??''),$trackRoot);}catch(Throwable){sf_mobile_hls_error(404);}}
 $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));require_once __DIR__.'/usage.php';umask(0007);
 if($ext==='m3u8'){$body=file_get_contents($file);if(!is_string($body))sf_mobile_hls_error(500);$scriptDir=rtrim(str_replace('\\','/',dirname((string)($_SERVER['SCRIPT_NAME']??'/api/v1/stream.php'))),'/');try{$body=sf_mobile_hls_rewrite($body,$file,$trackRoot,$hlsRoot,$tid,$scriptDir);}catch(Throwable){sf_mobile_hls_error(500);}header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');header('Content-Length: '.strlen($body));if($method!=='HEAD'){echo $body;sf_usage_record($uid,strlen($body),0,'hls');}exit;}
 $types=['ts'=>'video/mp2t','aac'=>'audio/aac','m4s'=>'video/iso.segment','mp4'=>'video/mp4'];if(!isset($types[$ext]))sf_mobile_hls_error(404);
 $size=filesize($file);$start=0;$end=$size-1;header('Accept-Ranges: bytes');
 if(isset($_SERVER['HTTP_RANGE'])){if(!preg_match('/^bytes=(\d*)-(\d*)$/D',trim($_SERVER['HTTP_RANGE']),$m)||($m[1]===''&&$m[2]==='')){header('Content-Range: bytes */'.$size);sf_mobile_hls_error(416);}if($m[1]==='')$start=max(0,$size-(int)$m[2]);else{$start=(int)$m[1];if($m[2]!=='')$end=min($end,(int)$m[2]);}if($start>$end||$start>=$size){header('Content-Range: bytes */'.$size);sf_mobile_hls_error(416);}http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);}
 header('Content-Type: '.$types[$ext]);header('Content-Length: '.($end-$start+1));if($method==='HEAD')exit;
 $fh=fopen($file,'rb');if(!$fh)sf_mobile_hls_error(500);fseek($fh,$start);$remaining=$end-$start+1;$sent=0;while($remaining>0&&!feof($fh)&&!connection_aborted()){$chunk=fread($fh,min(1048576,$remaining));if($chunk===false||$chunk==='')break;echo $chunk;$n=strlen($chunk);$remaining-=$n;$sent+=$n;}fclose($fh);sf_usage_record($uid,$sent,0,'hls');exit;
}
