<?php
declare(strict_types=1);
/** CLI-only, additive migration for HLS Music Lab auth_login_attempts. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "ERROR: falta pdo_sqlite.\n"); exit(2); }
$path = trim((string)getenv('HLS_MUSIC_LAB_SQLITE_DB')) ?: '/sparrowfy/database/library.sqlite';
if (!is_file($path) || !is_readable($path) || !is_writable($path)) { fwrite(STDERR, "ERROR: base no existe o PHP no tiene permisos de lectura/escritura: {$path}\n"); exit(2); }
try {
  $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA busy_timeout=5000');
  $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='auth_login_attempts'")->fetchColumn();
  if (!$tables) { fwrite(STDERR, "ERROR: no existe auth_login_attempts. No se modificó nada.\n"); exit(3); }
  $cols = [];
  foreach ($pdo->query('PRAGMA table_info(auth_login_attempts)') as $col) $cols[$col['name']] = true;
  foreach (['id','attempt_key'] as $required) {
    if (!isset($cols[$required])) { fwrite(STDERR, "ERROR: falta la columna esperada {$required}; esquema desconocido. No se modificó nada.\n"); exit(3); }
  }
  $changes = [];
  $pdo->beginTransaction();
  if (!isset($cols['attempted_at'])) {
    $pdo->exec('ALTER TABLE auth_login_attempts ADD COLUMN attempted_at INTEGER NOT NULL DEFAULT 0');
    $changes[] = 'añadida attempted_at';
  }
  if (!isset($cols['success'])) {
    $pdo->exec('ALTER TABLE auth_login_attempts ADD COLUMN success INTEGER NOT NULL DEFAULT 0 CHECK(success IN (0,1))');
    $changes[] = 'añadida success';
  }
  $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_attempts_key_time ON auth_login_attempts(attempt_key, attempted_at, success)');
  $pdo->commit();
  echo "Migración de autenticación completada.\nBase: {$path}\n";
  echo $changes ? ('Cambios: ' . implode(', ', $changes) . ".\n") : "Las columnas ya existían; no fue necesario añadirlas.\n";
  echo "Se conservó la tabla y sus filas existentes; las filas antiguas reciben attempted_at=0 y no cuentan como intentos recientes.\n";
  echo "Usuarios, contraseñas y sesiones no fueron modificados.\n";
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, 'ERROR: migración cancelada: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
  exit(1);
}
