<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
umask(0007);require dirname(__DIR__).'/lib/processing_queue.php';require dirname(__DIR__).'/lib/charts_scheduler.php';
try{$result=sfc_tick(in_array('--refresh',$argv,true));if(($result['state']??'')!=='busy')echo json_encode($result,JSON_UNESCAPED_UNICODE).PHP_EOL;}catch(Throwable $e){fwrite(STDERR,'Music Charts: '.$e->getMessage().PHP_EOL);exit(1);}
