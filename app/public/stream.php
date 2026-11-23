<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
lab_auth_require_login();
umask(0007);require_once __DIR__.'/lib/usage.php';
$usageUid=(string)($_SESSION['user_id']??'');
session_write_close();
$rel = (string)($_GET['path'] ?? '');
$rel = str_replace('\\', '/', $rel);
if ($rel === '' || str_contains($rel, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', $rel) || str_starts_with($rel, '/') || preg_match('#^[A-Za-z]:#', $rel)) { http_response_code(400); exit('Bad path'); }
$root = realpath(__DIR__ . '/hls_out');
$file = realpath(__DIR__ . '/hls_out/' . $rel);
if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) { http_response_code(404); exit('Not found'); }
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!in_array($ext, ['m3u8','ts','aac','m4s','mp4'], true)) { http_response_code(404); exit('Not found'); }
header('Cache-Control: private, no-store');
if ($ext === 'm3u8') {
    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    $body = file_get_contents($file);
    if (!is_string($body)) { http_response_code(500); exit('Read failed'); }
    $dir = dirname($rel); $base = rtrim($dir === '.' ? '' : $dir, '/');
    $scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/audio/stream.php'))), '/');
    $body = preg_replace_callback('/^(?!#)([^\r\n]+)$/m', static function(array $m) use ($base, $scriptDir): string {
        $item = trim($m[1]); if ($item === '' || preg_match('#^(?:https?:)?//#i', $item)) return $m[1];
        $path = ($base !== '' ? $base . '/' : '') . $item;
        return $scriptDir . '/stream.php?path=' . rawurlencode($path);
    }, $body) ?? $body;
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){echo $body;sf_usage_record($usageUid,strlen($body),0,'hls');} exit;
}
$types = ['ts'=>'video/mp2t','aac'=>'audio/aac','m4s'=>'video/iso.segment','mp4'=>'video/mp4'];
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . (string)filesize($file));
if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){$sent=readfile($file);sf_usage_record($usageUid,$sent===false?0:$sent,0,'hls');}
