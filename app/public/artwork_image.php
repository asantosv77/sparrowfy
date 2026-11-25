<?php declare(strict_types=1);
require __DIR__.'/auth.php';
require_once __DIR__.'/lib/mobile_sessions.php';

// La PWA usa cookie; la app usa el mismo token Bearer persistente hasta revocación.
$token = '';
$header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($header === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strtolower((string)$name) === 'authorization') { $header = (string)$value; break; }
    }
}
if (preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $header, $match)) $token = strtolower($match[1]);
if ($token === '') {
    lab_auth_require_login();
} else {
    $p = lab_auth_pdo();
    sf_mobile_sessions_schema($p);
    $q = $p->prepare('SELECT 1 FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.refresh_token_hash=? AND s.revoked_at IS NULL AND u.is_active=1 LIMIT 1');
    $q->execute([hash('sha256', $token)]);
    if (!$q->fetchColumn()) { http_response_code(401); exit; }
}
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$key=(string)($_GET['key']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$key)){http_response_code(404);exit;}
$file=__DIR__.'/data/artwork-images/'.$key.'.img';
if(!is_file($file)){http_response_code(404);exit;}
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);
if(!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)){http_response_code(404);exit;}
header('Content-Type: '.$mime);header('Content-Length: '.filesize($file));header('Cache-Control: private, max-age=86400');header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='HEAD')readfile($file);
