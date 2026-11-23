<?php
declare(strict_types=1);

// Ejecutar solo desde CLI. No cambia tablas ni datos de la biblioteca.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
$env = getenv('HLS_MUSIC_LAB_SQLITE_DB');
$path = is_string($env) && trim($env) !== '' ? trim($env) : '/sparrowfy/database/library.sqlite';
if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
    fwrite(STDERR, "No se puede leer/escribir la base de datos: {$path}\n");
    exit(1);
}
try {
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15]);
    $pdo->exec('PRAGMA busy_timeout=15000');
    $before = (string)$pdo->query('PRAGMA journal_mode')->fetchColumn();
    $after = (string)$pdo->query('PRAGMA journal_mode=WAL')->fetchColumn();
    $pdo->exec('PRAGMA wal_autocheckpoint=1000');
    echo "DB: {$path}\nJournal mode anterior: {$before}\nJournal mode actual: {$after}\nBusy timeout: 15000 ms\n";
    if (strtolower($after) !== 'wal') {
        fwrite(STDERR, "AVISO: SQLite no confirmó el modo WAL. No continúes sin revisar permisos y ubicación del archivo.\n");
        exit(2);
    }
    echo "OK: WAL activado. No se modificaron tablas ni credenciales.\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
