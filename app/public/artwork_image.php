<?php declare(strict_types=1);
require __DIR__.'/auth.php';lab_auth_require_login();
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$key=(string)($_GET['key']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$key)){http_response_code(404);exit;}
$file=__DIR__.'/data/artwork-images/'.$key.'.img';
if(!is_file($file)){http_response_code(404);exit;}
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);
if(!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)){http_response_code(404);exit;}
header('Content-Type: '.$mime);header('Content-Length: '.filesize($file));header('Cache-Control: private, max-age=86400');header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='HEAD')readfile($file);
