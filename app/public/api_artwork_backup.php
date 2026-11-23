<?php declare(strict_types=1);
require __DIR__.'/lib.php';require_once __DIR__.'/lib/external_artwork_backup.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('{}');}
$url=trim((string)($_POST['url']??''));
if(!sf_artwork_provider_url($url)){http_response_code(400);exit('{"available":false}');}
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
try{$r=sf_artwork_store($url,LAB_DATA.'/artwork-images');echo json_encode(['available'=>true,'local'=>'artwork_image.php?key='.$r['key']]);}
catch(SFArtworkBackupInProgress $e){http_response_code(202);header('Retry-After: 2');echo '{"available":false,"pending":true}';}
catch(Throwable $e){error_log('SparrowFy artwork backup: '.get_class($e).' / '.$e->getMessage());http_response_code(503);echo '{"available":false,"reason":"backup_unavailable"}';}
