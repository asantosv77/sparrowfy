<?php
/**
 * Reporta trabajos HLS incompletos sin manifiesto. Solo borra con --apply.
 * Ejecutar dentro del contenedor web:
 *   php /var/www/html/audio/scripts/cleanup_incomplete_hls.php
 *   php /var/www/html/audio/scripts/cleanup_incomplete_hls.php --apply
 */
declare(strict_types=1);

$appRoot = dirname(__DIR__);
$jsonRoot = $appRoot . '/data/albums';
$hlsRoot = '/sparrowfy/hls_out';
if (!is_dir($hlsRoot)) $hlsRoot = $appRoot . '/hls_out';
$apply = in_array('--apply', $argv ?? [], true);

if (!is_dir($jsonRoot) || !is_dir($hlsRoot)) {
    fwrite(STDERR, "ERROR: no se encuentra el directorio de álbumes JSON o HLS.\n");
    exit(2);
}
$hlsRealRoot = realpath($hlsRoot);
if ($hlsRealRoot === false) { fwrite(STDERR, "ERROR: raíz HLS inválida.\n"); exit(2); }

$referenced = [];
foreach (glob($jsonRoot . '/*/album.json') ?: [] as $jsonFile) {
    $album = json_decode((string)@file_get_contents($jsonFile), true);
    if (!is_array($album)) continue;
    foreach (($album['tracks'] ?? []) as $track) {
        if (!is_array($track)) continue;
        $hls = str_replace('\\', '/', trim((string)($track['hls'] ?? '')));
        $parts = explode('/', $hls);
        if (count($parts) !== 4 || $parts[0] !== 'hls_out' || in_array('..', $parts, true)) continue;
        $path = realpath($hlsRealRoot . '/' . $parts[1] . '/' . $parts[2]);
        if ($path !== false && str_starts_with($path, $hlsRealRoot . DIRECTORY_SEPARATOR)) $referenced[$path] = true;
    }
}

$candidates = [];
$roots = glob($hlsRealRoot . '/*', GLOB_ONLYDIR) ?: [];
foreach ($roots as $rootDir) {
    // Jobs HLS antiguos podían quedar directamente bajo hls_out/.
    $rootReal = realpath($rootDir);
    $rootName = basename($rootDir);
    // No tratar una carpeta de álbum (ID hexadecimal + slug) como un job.
    $looksLikeAlbumFolder = preg_match('/^[a-f0-9]{16}_/i', $rootName) === 1;
    if (!$looksLikeAlbumFolder && $rootReal !== false && !isset($referenced[$rootReal])
        && !is_file($rootReal . '/master.m3u8') && !is_file($rootReal . '/info.json')) {
        $candidates[] = $rootReal;
    }
    // Los jobs actuales viven un nivel por debajo de la carpeta del álbum.
    foreach (glob($rootDir . '/*', GLOB_ONLYDIR) ?: [] as $jobDir) {
        $jobReal = realpath($jobDir);
        if ($jobReal === false || !str_starts_with($jobReal, $hlsRealRoot . DIRECTORY_SEPARATOR)) continue;
        if (isset($referenced[$jobReal])) continue;
        if (is_file($jobReal . '/master.m3u8') || is_file($jobReal . '/info.json')) continue;
        $candidates[] = $jobReal;
    }
}
$candidates = array_values(array_unique($candidates));

echo "HLS Music Lab — limpieza de trabajos incompletos\nModo: " . ($apply ? 'APLICAR (eliminación)' : 'solo diagnóstico; no borra nada') . "\n";
echo "Candidatos sin master.m3u8/info.json y sin referencia en album.json: " . count($candidates) . "\n";
foreach ($candidates as $path) {
    echo ($apply ? 'ELIMINANDO: ' : 'REVISAR: ') . $path . "\n";
    if (!$apply) continue;
    // Revalidar la ubicación inmediatamente antes de eliminar.
    $real = realpath($path);
    if ($real === false || !str_starts_with($real, $hlsRealRoot . DIRECTORY_SEPARATOR) || isset($referenced[$real])) {
        echo "OMITIDO: la ruta cambió o está referenciada.\n";
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    if (!@rmdir($real)) echo "ERROR: no se pudo retirar el directorio; revisa permisos o archivos abiertos.\n";
}
echo $apply ? "Terminado. Revisa los mensajes anteriores.\n" : "No se eliminó ni modificó ningún archivo. Revisa la lista antes de ejecutar con --apply.\n";
