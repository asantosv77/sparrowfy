<?php
declare(strict_types=1);require_once __DIR__.'/lib/processing_queue.php';require_once __DIR__.'/lib/library_root.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$account=lab_auth_current_account();if(!$account){http_response_code(401);echo json_encode(['error'=>'Sesión caducada']);exit;}
if(!lab_auth_can_manage_albums()){http_response_code(403);echo json_encode(['error'=>'No autorizado']);exit;}
$q=sfq_db()->prepare("SELECT id FROM sf_processing_jobs WHERE user_id=? AND album_id=? AND state IN ('queued','running') AND operation IN ('upload','youtube','client_hls') AND json_extract(payload,'$.client_source') IS NULL ORDER BY created_at LIMIT 200");$q->execute([$account['id'],SF_LOOSE_ID]);echo json_encode(['ids'=>$q->fetchAll(PDO::FETCH_COLUMN)]);
