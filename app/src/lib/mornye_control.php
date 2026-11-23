<?php
declare(strict_types=1);
/** Local service control: fixed destination, no credentials exported. */
function sfmn_dir():string{return dirname(__DIR__).'/data/mornye-control';}
function sfmn_event(string $code,string $message,string $level='error'):void{
 $dir=sfmn_dir();$lock=fopen($dir.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX))return;
 try{$p=$dir.'/events.json';$rows=json_decode((string)@file_get_contents($p),true)?:[];
 $message=preg_replace('~https?://\S+~','[enlace omitido]',$message);
 $message=preg_replace('/(?i)(token|authorization|cookie|secret|password)\s*[:=]\s*\S+/','$1=[omitido]',$message);
 $message=mb_substr(strip_tags((string)$message),0,1500);$key=hash('sha256',$code.$message);$found=false;
 foreach($rows as &$row)if(($row['key']??'')===$key&&!empty($row['resolved']))return;unset($row);
 foreach($rows as &$row)if(($row['key']??'')===$key&&(str_starts_with($code,'download.failed.')||!($row['read']??false))){$row['last']=time();$row['count']++;$found=true;break;}unset($row);
 if(!$found)$rows[]=['id'=>bin2hex(random_bytes(8)),'key'=>$key,'code'=>$code,'message'=>$message,'level'=>$level,'first'=>time(),'last'=>time(),'count'=>1,'read'=>false];
 sfmn_write($p,array_slice($rows,-200));
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function sfmn_write(string $path,array $data):void{
 $temp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';
 try{if(file_put_contents($temp,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX)===false)throw new RuntimeException('No se pudo guardar el registro de Mornye');chmod($temp,0660);if(!rename($temp,$path))throw new RuntimeException('No se pudo publicar el registro');}finally{if(is_file($temp))unlink($temp);}
}
function sfmn_request(string $operation,array $payload=[]):array{
 $paths=['extensions'=>'/api/v1/extensions','info'=>'/api/v1/info','providers'=>'/api/get_download_services','settings'=>'/api/load_settings','save'=>'/api/save_settings','sessions'=>'/api/get_signed_sessions','jobs'=>'/api/v1/downloads'];
 if(!isset($paths[$operation]))throw new InvalidArgumentException('Operacion no permitida');
 $ch=curl_init(rtrim((string)(getenv('MORNYE_API_URL') ?: 'http://mornye-api:8000'), '/').$paths[$operation]);$body='';
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_WRITEFUNCTION=>static function($ch,$part)use(&$body){if(strlen($body)+strlen($part)>2097152)return 0;$body.=$part;return strlen($part);}]);
 if(!in_array($operation,['info','jobs','extensions'],true))curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload?:new stdClass(),JSON_THROW_ON_ERROR)]);
 $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 if($ok===false||$status<200||$status>=300)throw new RuntimeException('Mornye no responde correctamente (HTTP '.$status.'). Comprueba el contenedor, sus extensiones y la sesion.');
 $x=json_decode($body,true,64,JSON_THROW_ON_ERROR);if(!is_array($x)||isset($x['error']))throw new RuntimeException('Mornye devolvio una respuesta incompatible o un error.');
 $x=$x['result']??$x;if($x===null)return [];if(!is_array($x))throw new RuntimeException('Formato de respuesta de Mornye incompatible');return $x;
}
