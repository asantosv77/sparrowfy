<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$root=dirname(__DIR__);$backup=realpath($root.'/'.trim((string)file_get_contents($root.'/data/webcode-backup-current.txt')));$backupRoot=realpath($root.'/data/backups');
if(!$backup||dirname($backup)!==$backupRoot||!str_starts_with(basename($backup),'webcode-'))throw new RuntimeException('Respaldo inválido');
$m=json_decode(file_get_contents($backup.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if(!in_array('--restore',$argv,true)){echo "Respaldo: ".$backup."\nFrase de solicitud: da rollback al procesamiendo webcode\nUsar --restore solo al confirmar la reversión. No se modifica catálogo ni audio.\n";exit;}
require_once $root.'/lib/processing_queue.php';$db=sfq_db();if((int)$db->query("SELECT COUNT(*) FROM sf_processing_jobs WHERE (operation='client_hls' OR (operation='youtube' AND json_extract(payload,'$.client_source') IS NOT NULL)) AND state IN ('queued','running')")->fetchColumn())throw new RuntimeException('Espera a que terminen los trabajos WebCodecs antes del rollback');
foreach($m['post_hashes']??[] as $file=>$hash){if(!is_file($root.'/'.$file)||!hash_equals($hash,hash_file('sha256',$root.'/'.$file)))throw new RuntimeException('Hubo cambios posteriores en '.$file.'. Requiere revisión antes de restaurar.');}
foreach($m['changed']??[] as $file){if(!is_file($backup.'/'.$file))throw new RuntimeException('Falta respaldo de '.$file);if(!copy($backup.'/'.$file,$root.'/'.$file))throw new RuntimeException('No se pudo restaurar '.$file);}
foreach($m['new']??[] as $file){if($file=== 'scripts/rollback_webcode.php')continue;if(is_file($root.'/'.$file)&&!unlink($root.'/'.$file))throw new RuntimeException('No se pudo retirar '.$file);}
echo "Rollback del código WebCodecs completado. Catálogo y audio conservados; temporales conservados para revisión.\n";
