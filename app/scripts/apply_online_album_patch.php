<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$audioRoot = realpath(__DIR__ . '/..');
$stage = __DIR__ . '/online_album_patch';
if ($audioRoot === false || !is_dir($stage)) {
    fwrite(STDERR, "ERROR: no se encontró el directorio del proyecto o los archivos de parche.\n");
    exit(1);
}

$map = [
    $stage . '/album_edit.php' => $audioRoot . '/album_edit.php',
    $stage . '/api_youtube.php' => $audioRoot . '/api_youtube.php',
    $stage . '/player.js' => $audioRoot . '/assets/player.js',
    $stage . '/youtube_url_normalizer.php' => $audioRoot . '/youtube_url_normalizer.php',
    $stage . '/create_album.php' => $audioRoot . '/create_album.php',
];
foreach ($map as $src => $dst) {
    if (!is_file($src) || !is_readable($src) || !is_dir(dirname($dst)) || !is_writable(dirname($dst)) || (file_exists($dst) && (!is_file($dst) || !is_writable($dst)))) {
        fwrite(STDERR, "ERROR: archivo de origen/destino no disponible o no escribible: $dst\n");
        exit(1);
    }
}

$indexFile = $audioRoot . '/index.php';
if (!is_file($indexFile) || !is_readable($indexFile) || !is_writable($indexFile)) {
    fwrite(STDERR, "ERROR: index.php no está disponible para actualizar el acceso a Nuevo álbum.\n");
    exit(1);
}
$indexOriginal = file_get_contents($indexFile);
$oldLink = '<a class="btn primary" href="album_edit.php">＋ Nuevo álbum</a>';
$newLink = '<a class="btn primary" href="create_album.php">＋ Nuevo álbum</a>';
if (!is_string($indexOriginal)) {
    fwrite(STDERR, "ERROR: no se pudo leer index.php.\n");
    exit(1);
}
$alreadyUpdated = str_contains($indexOriginal, $newLink);
if (!$alreadyUpdated && substr_count($indexOriginal, $oldLink) !== 1) {
    fwrite(STDERR, "ERROR: no se encontró exactamente el enlace original «＋ Nuevo álbum» en index.php. No se modificó nada.\n");
    exit(1);
}

$stamp = date('Ymd-His');
$backups = [];
if (!$alreadyUpdated) {
    $indexBackup = $indexFile . '.bak-create-album-choice-' . $stamp;
    if (!copy($indexFile, $indexBackup)) {
        fwrite(STDERR, "ERROR: no se pudo respaldar index.php. No se aplicó el parche.\n");
        exit(1);
    }
    echo "Copia de seguridad: $indexBackup\n";
} else {
    $indexBackup = null;
    echo "index.php ya apunta a create_album.php; se conservará ese enlace.\n";
}

foreach ($map as $src => $dst) {
    if (is_file($dst)) {
        $backup = $dst . '.bak-online-album-' . $stamp;
        if (!copy($dst, $backup)) {
            fwrite(STDERR, "ERROR: no se pudo crear copia de seguridad de $dst. No se aplicó el parche.\n");
            exit(1);
        }
        $backups[$dst] = $backup;
        echo "Copia de seguridad: $backup\n";
    } else {
        $backups[$dst] = null;
        echo "Se instalará archivo nuevo: $dst\n";
    }
}

$ok = true;
foreach ($map as $src => $dst) {
    if (!copy($src, $dst)) {
        fwrite(STDERR, "ERROR: no se pudo instalar $dst.\n");
        $ok = false;
        break;
    }
}

if ($ok && !$alreadyUpdated) {
    $backups[$indexFile] = $indexBackup;
    $indexUpdated = str_replace($oldLink, $newLink, $indexOriginal, $replaceCount);
    if ($replaceCount !== 1 || file_put_contents($indexFile, $indexUpdated, LOCK_EX) === false) {
        $ok = false;
        fwrite(STDERR, "ERROR: no se pudo actualizar el enlace en index.php.\n");
    } else {
        echo "Enlace «＋ Nuevo álbum» actualizado para abrir el selector.\n";
    }
}

$phpFiles = [$audioRoot . '/album_edit.php', $audioRoot . '/api_youtube.php', $audioRoot . '/youtube_url_normalizer.php', $audioRoot . '/create_album.php'];
if ($ok) {
    foreach ($phpFiles as $file) {
        $output = [];
        $code = 1;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        echo implode("\n", $output), "\n";
        if ($code !== 0) { $ok = false; break; }
    }
}

if (!$ok) {
    foreach ($backups as $dst => $backup) {
        if (is_string($backup) && is_file($backup)) @copy($backup, $dst);
        elseif ($backup === null && is_file($dst)) @unlink($dst);
    }
    fwrite(STDERR, "ERROR: falló la instalación o la validación; se restauraron las copias de seguridad.\n");
    exit(1);
}

echo "Parche instalado y sintaxis PHP validada.\n";
echo "Nota: no se descargó audio ni se modificó la base de datos.\n";
