<?php
declare(strict_types=1);
/** Explicit password reset for one existing user. CLI only; no user creation. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "Falta pdo_sqlite en PHP CLI.\n"); exit(2); }
$username = trim((string)getenv('HLS_RESET_USER'));
$password = (string)getenv('HLS_RESET_PASSWORD');
if (!preg_match('/^[A-Za-z0-9_.@-]{3,80}$/', $username)) { fwrite(STDERR, "Define HLS_RESET_USER con el nombre exacto del usuario existente.\n"); exit(2); }
if (strlen($password) < 14 || strlen($password) > 72 || str_contains($password, "\0")) { fwrite(STDERR, "HLS_RESET_PASSWORD debe tener entre 14 y 72 bytes.\n"); exit(2); }
$path = trim((string)getenv('HLS_MUSIC_LAB_SQLITE_DB')) ?: '/sparrowfy/database/library.sqlite';
if (!is_file($path) || !is_writable($path)) { fwrite(STDERR, "La base no existe o no es escribible desde PHP CLI: {$path}\n"); exit(2); }
try {
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]);
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->beginTransaction();
    $q = $pdo->prepare('SELECT id, username, is_active FROM users WHERE username = ? COLLATE NOCASE LIMIT 1');
    $q->execute([$username]);
    $user = $q->fetch();
    if (!$user) { $pdo->rollBack(); fwrite(STDERR, "No existe ese usuario. No se creó ningún usuario ni se cambió nada.\n"); exit(3); }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $now = time();
    $u = $pdo->prepare('UPDATE users SET password_hash=?, updated_at=? WHERE id=?');
    $u->execute([$hash, $now, $user['id']]);
    $pdo->commit();
    printf("Contraseña actualizada para el usuario existente: %s\n", (string)$user['username']);
    echo "La contraseña no se imprimió. Este script no cambia el estado activo/inactivo de la cuenta.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'ERROR: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    unset($password);
}
