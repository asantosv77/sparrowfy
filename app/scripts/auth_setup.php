<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/auth.php';
$user = trim((string)getenv('HLS_ADMIN_USER'));
$password = (string)getenv('HLS_ADMIN_PASSWORD');
if (!preg_match('/^[A-Za-z0-9_.@-]{3,80}$/D', $user)
    || strlen($password) < 14 || strlen($password) > 72 || str_contains($password, "\0")) {
    fwrite(STDERR, "Indica un usuario de 3 a 80 caracteres y una contraseña de 14 a 72 bytes.\n");
    exit(2);
}
try {
    $db = lab_auth_pdo();
    $db->exec('BEGIN IMMEDIATE');
    $count = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        $get = $db->prepare('SELECT username,role,is_active,can_manage_albums FROM users WHERE id=?');
        $get->execute([LAB_PROTECTED_ADMIN_ID]);
        $existing = $get->fetch();
        if (!$existing || $existing['role'] !== 'admin' || !(int)$existing['is_active'] || !(int)$existing['can_manage_albums']) {
            throw new RuntimeException('El catálogo ya contiene usuarios y requiere revisión del administrador principal.');
        }
        $db->exec('COMMIT');
        echo "La cuenta principal ya está configurada. Se conservan sus credenciales.\n";
        exit(0);
    }
    $insert = $db->prepare("INSERT INTO users(id,username,password_hash,role,is_active,can_manage_albums,created_at,updated_at) VALUES(?,?,?,'admin',1,1,?,?)");
    $now = gmdate('c');
    $insert->execute([LAB_PROTECTED_ADMIN_ID, $user, password_hash($password, PASSWORD_DEFAULT), $now, $now]);
    $db->exec('COMMIT');
    echo "Administrador principal creado. Ya puedes iniciar sesión.\n";
} catch (Throwable $e) {
    if (isset($db)) { try { $db->exec('ROLLBACK'); } catch (Throwable) {} }
    fwrite(STDERR, "Alta no completada: ".$e->getMessage()."\n");
    exit(1);
}
