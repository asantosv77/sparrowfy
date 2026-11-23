<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/processing_queue.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$account = lab_auth_current_account();
if (!$account) {
    http_response_code(401);
    echo json_encode(['error' => 'Sesión caducada']);
    exit;
}
$albumId = (string) ($_GET['album_id'] ?? '');
if ($albumId !== '' && !preg_match('/^[a-f0-9]{16}$/', $albumId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Álbum inválido']);
    exit;
}
// El usuario debe tener permiso de gestionar este álbum, no basta con conocer el ID.
lab_auth_require_album_manager();
$album = $albumId !== '' ? lab_load_album($albumId) : null;
if ($albumId !== '' && !$album) {
    http_response_code(404);
    echo json_encode(['error' => 'Álbum no encontrado']);
    exit;
}
require_once __DIR__.'/lib/album_import_jobs.php';
$tracked=$_GET['tracked_ids']??'';
if(!is_string($tracked)||strlen($tracked)>3300){http_response_code(400);echo json_encode(['error'=>'IDs inválidos']);exit;}
$trackedIds=$tracked===''?[]:explode(',',$tracked);
if(count($trackedIds)>100||array_filter($trackedIds,static fn($id)=>!preg_match('/^[a-f0-9]{32}$/D',$id))){http_response_code(400);echo json_encode(['error'=>'IDs inválidos']);exit;}
$finished=sfq_album_import_finished(sfq_db(),$albumId,(string)$account['id'],($account['role']??'')==='admin',$trackedIds);
$jobs=sfq_album_import_jobs(sfq_db(),$albumId,(string)$account['id'],($account['role']??'')==='admin');
$titles=[];
foreach($jobs as &$job){
 $id=(string)$job['album_id'];
 if(!array_key_exists($id,$titles)){$a=lab_load_album($id);$titles[$id]=(string)($a['title']??'Álbum');}
 $job['album_title']=$titles[$id];
}
unset($job);
echo json_encode(['success'=>true,'jobs'=>$jobs,'finished'=>$finished],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
