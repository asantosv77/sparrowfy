<?php
declare(strict_types=1);
/** Protected, bounded metadata transport. Provider parsers live in external_charts.php. */
function sfc_http(string $url):array {
 $error=null;for($attempt=0;$attempt<2;$attempt++){try{return sfc_http_once($url);}catch(Throwable $e){$error=$e;if(!preg_match('/HTTP (0|429|500|502|503|504)\)/',$e->getMessage()))throw $e;}}
 throw $error;
}
function sfc_http_once(string $url):array {
 $u=parse_url($url);$host=$u['host']??'';
 if(($u['scheme']??'')!=='https'||!in_array($host,['rss.marketingtools.apple.com','itunes.apple.com','www.billboard.com','api.deezer.com'],true)||isset($u['user'])||isset($u['pass'])||isset($u['port']))throw new RuntimeException('Fuente de charts no permitida');
 $ip=gethostbyname($host);if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_GLOBAL_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('No se pudo resolver el proveedor');
 $body='';$c=curl_init($url);curl_setopt_array($c,[CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_USERAGENT=>'SparrowFy/1.0 (Music Charts)',CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_WRITEFUNCTION=>static function($c,$part)use(&$body){if(strlen($body)+strlen($part)>6291456)return 0;$body.=$part;return strlen($part);}]);
 $ok=curl_exec($c);$code=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
 if($ok===false||$code!==200)throw new RuntimeException('El proveedor de rankings no respondió (HTTP '.$code.')');
 if($host==='www.billboard.com')return ['html'=>$body];
 $data=json_decode($body,true,64,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Formato de ranking incompatible');return $data;
}
