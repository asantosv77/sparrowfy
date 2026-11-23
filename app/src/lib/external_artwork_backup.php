<?php declare(strict_types=1);
/** Local safety copy of public provider artwork. Never proxy arbitrary URLs.
 * Provider remains the browser source; local images are served only on failure.
 * Network changes belong here. See scripts/ARTWORK_CACHE.md.
 */
class SFArtworkBackupInProgress extends RuntimeException {}
function sf_artwork_provider_url(string $url): bool {
 $u=parse_url($url);
 return is_array($u)&&strlen($url)<=2048&&($u['scheme']??'')==='https'&&!isset($u['user'])&&!isset($u['pass'])&&!isset($u['port'])&&
 preg_match('/(^|\.)(mzstatic\.com|dzcdn\.net|coverartarchive\.org|archive\.org)$/i',(string)($u['host']??''))===1;
}
function sf_artwork_key(string $url): string {return hash('sha256',$url);}
function sf_artwork_image_info(string $bytes): ?array {
 if(strlen($bytes)>3*1024*1024)return null;
 $info=@getimagesizefromstring($bytes);
 if(!$info||!in_array($info['mime']??'',['image/jpeg','image/png','image/webp','image/gif'],true)||$info[0]<1||$info[1]<1||$info[0]>8192||$info[1]>8192)return null;
 return ['mime'=>$info['mime'],'width'=>$info[0],'height'=>$info[1],'size'=>strlen($bytes)];
}
function sf_artwork_download(string $url): string {
 $deadline=microtime(true)+10;
 for($redirect=0;$redirect<4;$redirect++){
  if(!sf_artwork_provider_url($url))throw new RuntimeException('Proveedor no permitido');
  $host=(string)parse_url($url,PHP_URL_HOST);$ips=gethostbynamel($host)?:[];$ip=null;
  foreach($ips as $candidate)if(filter_var($candidate,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)){$ip=$candidate;break;}
  if(!$ip)throw new RuntimeException('Dirección del proveedor no disponible');
  $remaining=(int)(($deadline-microtime(true))*1000);if($remaining<1)throw new RuntimeException('Tiempo agotado');
  $bytes='';$location='';$ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT_MS=>min(3000,$remaining),CURLOPT_TIMEOUT_MS=>$remaining,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'SparrowFy/1.0 artwork-backup',CURLOPT_HTTPHEADER=>['Accept: image/jpeg,image/png,image/webp,image/gif'],CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$bytes){if(strlen($bytes)+strlen($chunk)>3*1024*1024)return 0;$bytes.=$chunk;return strlen($chunk);},CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$location){if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);}]);
  $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
  if($ok===false)throw new RuntimeException('No se pudo obtener la imagen');
  if($status>=300&&$status<400){
   if(str_starts_with($location,'/'))$location='https://'.$host.$location;
   $url=$location;continue;
  }
  if($status!==200||!sf_artwork_image_info($bytes))throw new RuntimeException('Imagen no válida');
  return $bytes;
 }
 throw new RuntimeException('Demasiadas redirecciones');
}
function sf_artwork_store(string $url,string $dir): array {
 if(!sf_artwork_provider_url($url))throw new InvalidArgumentException('Proveedor no permitido');
 $key=sf_artwork_key($url);$file=$dir.'/'.$key.'.img';
 if(is_file($file))return ['key'=>$key,'cached'=>true];
 if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear el respaldo');
 $lock=fopen($dir.'/'.$key.'.lock','c');if(!$lock)throw new RuntimeException('Respaldo no disponible');
 try{
  if(!flock($lock,LOCK_EX|LOCK_NB))throw new SFArtworkBackupInProgress('Respaldo en curso');
  if(is_file($file))return ['key'=>$key,'cached'=>true];
  $bytes=sf_artwork_download($url);$temp=$file.'.'.bin2hex(random_bytes(4)).'.tmp';
  try{if(file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('No se pudo guardar la imagen');chmod($temp,0660);if(!rename($temp,$file))throw new RuntimeException('No se pudo publicar el respaldo');}finally{if(is_file($temp))@unlink($temp);}
  return ['key'=>$key,'cached'=>false];
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
