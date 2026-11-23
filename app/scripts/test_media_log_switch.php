<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$fixture=dirname(__DIR__).'/backups/media-switch-test-'.bin2hex(random_bytes(6));
mkdir($fixture.'/data',02770,true);define('LAB_DATA',$fixture.'/data');
require dirname(__DIR__).'/lib/media_log.php';
function verify(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$path=LAB_DATA.'/config.json';$log=LAB_DATA.'/media-log/events.jsonl';
sf_media_event('test.default');verify(count(file($log))===1,'Default enabled');
file_put_contents($path,json_encode(['media_operations_log_enabled'=>false]));usleep(2100000);
sf_media_event('test.disabled');verify(count(file($log))===1,'Disabled must preserve without append');
file_put_contents($path,json_encode(['media_operations_log_enabled'=>true]));usleep(2100000);
sf_media_event('test.enabled');verify(count(file($log))===2,'Re-enable must append');
verify(!str_contains(file_get_contents($log),'test.disabled'),'No disabled record');
echo "PASS media operations log default/on/off and live worker refresh\n";
