<?php
declare(strict_types=1);

/**
 * HLS Music Lab - switch session storage from SQLite to PHP's native file handler.
 * Run once from CLI inside the web container. Makes a timestamped auth.php backup.
 * Does not change users, passwords, permissions, or the SQLite database.
 */
$authFile = '/var/www/html/audio/auth.php';
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
if (!is_file($authFile) || !is_readable($authFile) || !is_writable($authFile)) {
    fwrite(STDERR, "ERROR: auth.php no existe o no tiene permisos de lectura/escritura: {$authFile}\n");
    exit(1);
}
$source = file_get_contents($authFile);
if (!is_string($source)) {
    fwrite(STDERR, "ERROR: no se pudo leer auth.php\n");
    exit(1);
}
$line = 'session_set_save_handler(new LabSqliteSessionHandler(), true);';
$count = substr_count($source, $line);
if ($count === 0) {
    if (strpos($source, 'session_set_save_handler(') === false) {
        echo "Sin cambios: auth.php ya no registra el manejador SQLite personalizado.\n";
        exit(0);
    }
    fwrite(STDERR, "ERROR: se encontró un manejador personalizado distinto; no se modificó nada.\n");
    exit(2);
}
if ($count !== 1) {
    fwrite(STDERR, "ERROR: se esperaba una sola referencia al manejador SQLite; encontradas: {$count}. No se modificó nada.\n");
    exit(2);
}
$backup = $authFile . '.bak-file-sessions-' . date('Ymd-His');
if (!copy($authFile, $backup)) {
    fwrite(STDERR, "ERROR: no se pudo crear respaldo: {$backup}\n");
    exit(1);
}
$replacement = "// Sesiones gestionadas por el manejador nativo de PHP (archivos), no por SQLite.\n    // Evita competir por bloqueos de escritura con usuarios y catálogo en library.sqlite.\n    // Se conserva el nombre HLSMLSESSID y la configuración segura de cookies.\n    // session_set_save_handler(new LabSqliteSessionHandler(), true);";
$updated = str_replace($line, $replacement, $source, $replaced);
if ($replaced !== 1 || !is_string($updated)) {
    fwrite(STDERR, "ERROR: no se pudo aplicar el cambio; el respaldo queda en {$backup}\n");
    exit(1);
}
$tmp = $authFile . '.tmp-' . getmypid();
if (file_put_contents($tmp, $updated, LOCK_EX) === false || !rename($tmp, $authFile)) {
    @unlink($tmp);
    fwrite(STDERR, "ERROR: no se pudo escribir el archivo actualizado. Respaldo: {$backup}\n");
    exit(1);
}
echo "OK: auth.php actualizado para usar sesiones nativas de PHP (archivos).\n";
echo "Respaldo: {$backup}\n";
echo "No se modificaron usuarios, contraseñas, permisos ni library.sqlite.\n";
echo "Verifica sintaxis con: php -l {$authFile}\n";
