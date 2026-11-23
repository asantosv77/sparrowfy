<?php declare(strict_types=1);if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/lib/playback_diagnostics.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
check(!sf_playback_debug_settings([])['enabled'],'Diagnóstico apagado por defecto');
$e=sf_playback_clean_event(['event'=>'hls_error','track'=>'0123456789abcdef','run'=>'abc','reason'=>'fragLoadError','position'=>12,'url'=>'https://private','cookie'=>'secret','password'=>'secret']);
check($e!==null&&$e['position']===12.0,'Métrica válida');check(!isset($e['url'])&&!isset($e['cookie'])&&!isset($e['password']),'Excluir datos sensibles');
check(sf_playback_clean_event(['event'=>'<script>'])===null,'Rechazar evento desconocido');
$dir=dirname(__DIR__).'/data/.test-diagnostics-'.bin2hex(random_bytes(4));mkdir($dir,0770);
try{file_put_contents($dir.'/playback-2000-01-01.jsonl',"{}\n");touch($dir.'/playback-2000-01-01.jsonl',946684800);$count=sf_playback_write_events($dir,'test-user',array_fill(0,40,$e),7);check($count===30,'Límite de lote');check(!file_exists($dir.'/playback-2000-01-01.jsonl'),'Retención');$rows=sf_playback_read_events($dir,5);check(count($rows)===5&&$rows[0]['user']==='test-user','Lectura acotada');echo "PASS: configuración, privacidad, límite de lote, retención y lectura\n";}finally{foreach(glob($dir.'/*')?:[] as $f)unlink($f);if(is_file($dir.'/.write.lock'))unlink($dir.'/.write.lock');rmdir($dir);}
