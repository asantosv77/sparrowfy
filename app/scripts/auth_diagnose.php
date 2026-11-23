<?php
declare(strict_types=1);
/** Read-only diagnostic for HLS Music Lab authentication. CLI only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "ERROR: falta pdo_sqlite en PHP CLI.\n"); exit(2); }
$path = trim((string)getenv('HLS_MUSIC_LAB_SQLITE_DB')) ?: '/sparrowfy/database/library.sqlite';
echo "HLS Music Lab auth diagnostic (solo lectura)\n";
echo "DB path: {$path}\n";
if (!is_file($path) || !is_readable($path)) { fwrite(STDERR, "ERROR: la base no existe o no es legible desde este contenedor.\n"); exit(2); }
try {
    $pdo = new PDO('sqlite:file:' . $path . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]);
    $pdo->exec('PRAGMA query_only=ON');
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('users','auth_sessions','auth_login_attempts') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    echo 'Auth tables: ' . ($tables ? implode(', ', $tables) : '(ninguna)') . "\n";
    if (!in_array('users', $tables, true)) { echo "RESULT: falta la tabla users; auth_setup.php debe crearla en ESTA misma base.\n"; exit(1); }
    $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll();
    $columnNames = array_map(static fn(array $c): string => (string)$c['name'], $columns);
    echo 'users columns: ' . implode(', ', $columnNames) . "\n";
    foreach (['id','username','password_hash','role','is_active'] as $required) {
        if (!in_array($required, $columnNames, true)) { echo "RESULT: falta columna requerida users.{$required}; posible esquema incompatible.\n"; exit(1); }
    }
    $users = $pdo->query('SELECT username, role, is_active, password_hash FROM users ORDER BY id')->fetchAll();
    echo 'Users found: ' . count($users) . "\n";
    foreach ($users as $u) {
        $hash = (string)$u['password_hash'];
        $kind = str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$') ? 'bcrypt' : (str_starts_with($hash, '$argon2id$') ? 'argon2id' : (str_starts_with($hash, '$argon2i$') ? 'argon2i' : 'formato-desconocido'));
        printf("- username=%s | role=%s | active=%s | hash_format=%s | hash_length=%d\n", (string)$u['username'], (string)$u['role'], (int)$u['is_active'] === 1 ? 'yes' : 'no', $kind, strlen($hash));
    }
    echo "\nNo se han mostrado contraseñas ni hashes y no se ha escrito en la base.\n";
    echo "RESULT: compara el nombre exacto (sin distinguir mayúsculas) y confirma que el usuario deseado está activo.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
