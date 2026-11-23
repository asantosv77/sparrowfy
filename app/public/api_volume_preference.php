<?php
require __DIR__.'/lib.php';require_once __DIR__.'/lib/loudness.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
$u=lab_auth_current_account();$enabled=($_POST['enabled']??'')==='1';
sf_loudness_db()->prepare('INSERT INTO sf_volume_preferences(user_id,enabled) VALUES(?,?) ON CONFLICT(user_id) DO UPDATE SET enabled=excluded.enabled')->execute([(string)$u['id'],(int)$enabled]);echo json_encode(['enabled'=>$enabled]);
