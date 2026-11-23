<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "Falta pdo_sqlite.\n"); exit(2); }
$path = trim((string)(getenv('HLS_MUSIC_LAB_SQLITE_DB') ?: '')) ?: '/sparrowfy/database/library.sqlite';
if (!is_file($path) || !is_readable($path) || !is_writable($path)) { fwrite(STDERR, "No se puede leer/escribir la base: $path\n"); exit(2); }
try {
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 15]);
    $pdo->exec('PRAGMA busy_timeout=15000');
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    if (!$tables) throw new RuntimeException('No existe la tabla users. Ejecuta el instalador inicial de autenticación primero.');
    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(users)') as $col) $columns[(string)$col['name']] = true;
    if (!isset($columns['can_manage_albums'])) {
        $pdo->exec('ALTER TABLE users ADD COLUMN can_manage_albums INTEGER NOT NULL DEFAULT 0');
        echo "Columna can_manage_albums añadida (todas las cuentas existentes quedan sin permiso de edición salvo admin).\n";
    } else {
        echo "La columna can_manage_albums ya existe; no se modificó.\n";
    }
    echo "Migración completada. No se cambiaron contraseñas ni cuentas.\n";
} catch (Throwable $e) { fwrite(STDERR, 'Error de migración: ' . $e->getMessage() . "\n"); exit(1); }
