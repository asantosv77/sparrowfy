<?php
function sf_acoustid_health():array{
 $r=json_decode((string)@file_get_contents(dirname(__DIR__).'/data/acoustid-health.json'),true);
 return array_merge(['state'=>'unknown','retry_after'=>0],is_array($r)?$r:[]);
}
function sf_acoustid_health_set(bool $ok,string $key):void{
 $dir=dirname(__DIR__).'/data';$file=$dir.'/acoustid-health.json';
 $r=['state'=>$ok?'ok':'unavailable','checked_at'=>time(),'retry_after'=>$ok?0:time()+120,'key_stamp'=>hash('sha256',$key)];
 $temp=$file.'.'.bin2hex(random_bytes(4));
 if(@file_put_contents($temp,json_encode($r),LOCK_EX)!==false){@chmod($temp,0660);if(!@rename($temp,$file))@unlink($temp);}
}
function sf_acoustid_skip_message():string{return 'La identificación con AcoustID no está disponible temporalmente y se omitió en esta tarea. Puedes editar los metadatos manualmente y continuar usando la aplicación. Se volverá a intentar en una próxima solicitud.';}
