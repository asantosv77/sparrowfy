<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !lab_auth_verify_csrf(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
    http_response_code(403); exit('Solicitud no válida.');
}
if(!empty($_SESSION['user_id'])&&!empty($_SESSION['device_id']))sf_device_revoke(sf_remember_db(),(string)$_SESSION['user_id'],false,(string)$_SESSION['user_id'],(string)$_SESSION['device_id']);
sf_remember_forget();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => 'Lax']);
}
session_destroy();
header('Location: login.php', true, 303);
