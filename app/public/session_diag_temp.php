<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
lab_auth_require_login();
lab_auth_require_admin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$account = lab_auth_current_account();

echo json_encode([
    'session_status' => session_status() === PHP_SESSION_ACTIVE
        ? 'active' : 'inactive',
    'session_id_sha256' => hash('sha256', session_id()),
    'save_handler' => ini_get('session.save_handler'),
    'save_path' => ini_get('session.save_path'),
    'serialize_handler' => ini_get('session.serialize_handler'),
    'fields' => [
        'user_id' => isset($_SESSION['user_id']),
        'username' => isset($_SESSION['username']),
        'role' => isset($_SESSION['role']),
        'csrf_token' => isset($_SESSION['csrf_token']),
    ],
    'account_valid' => $account !== null,
    'is_active' => $account !== null
        ? (int)$account['is_active'] === 1 : false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
