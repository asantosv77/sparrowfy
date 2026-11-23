<?php
declare(strict_types=1);
$root = '/var/www/html/audio';
$index = $root . '/index.php';
$page = $root . '/change_password.php';
$source = is_file($index) ? file_get_contents($index) : false;
if (!is_string($source) || $source === '') { fwrite(STDERR, "ERROR: no se encontró index.php en $root\n"); exit(1); }
if (strpos($source, 'href="change_password.php"') === false) {
    $needle = '  <nav class="lab-nav">';
    if (strpos($source, $needle) === false) { fwrite(STDERR, "ERROR: no se encontró el menú de navegación esperado; no se modificó nada.\n"); exit(1); }
    $backup = $index . '.bak.password-menu.' . date('Ymd_His');
    if (!copy($index, $backup)) { fwrite(STDERR, "ERROR: no se pudo respaldar index.php.\n"); exit(1); }
    $replacement = $needle . "\n    <a href=\"change_password.php\">Mi cuenta · Cambiar contraseña</a>";
    $updated = str_replace($needle, $replacement, $source, $count);
    if ($count !== 1 || file_put_contents($index, $updated, LOCK_EX) === false) { @copy($backup, $index); fwrite(STDERR, "ERROR: no se pudo actualizar index.php; se restauró el respaldo.\n"); exit(1); }
    echo "Enlace agregado al menú. Respaldo: $backup\n";
} else echo "El enlace Mi cuenta ya existe.\n";
if (!is_file($page)) {
    $sourcePage = __DIR__ . '/../change_password.php';
    // El instalador está dentro de scripts/; la página se incluye en el ZIP en audio/change_password.php.
    $sourcePage = dirname(__DIR__) . '/change_password.php';
    if (!is_file($sourcePage)) { fwrite(STDERR, "ERROR: falta audio/change_password.php en el paquete.\n"); exit(1); }
    if (!copy($sourcePage, $page)) { fwrite(STDERR, "ERROR: no se pudo instalar change_password.php.\n"); exit(1); }
    echo "Página change_password.php instalada.\n";
} else echo "change_password.php ya existe; no se sobrescribió.\n";
$lines = []; $status = 0;
exec('php -l ' . escapeshellarg($page) . ' 2>&1', $lines, $status);
echo implode("\n", $lines), "\n";
if ($status !== 0) { fwrite(STDERR, "ERROR: falló la validación de sintaxis PHP.\n"); exit(1); }
echo "Listo. El cambio de contraseña está disponible para todo usuario autenticado.\n";
