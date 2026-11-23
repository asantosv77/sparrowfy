<?php
/**
 * Auditoría de residuos JSON/HLS para HLS Music Lab.
 * Solo lectura: nunca elimina ni modifica archivos.
 * Ejecutar desde el contenedor www:
 *   php /var/www/html/audio/scripts/audit_hls_orphans.php
 */
declare(strict_types=1);

$appRoot  = dirname(__DIR__);
$jsonRoot = $appRoot . '/data/albums';
$hlsRoot  = '/sparrowfy/hls_out';
if (!is_dir($hlsRoot)) {
    $hlsRoot = $appRoot . '/hls_out';
}

if (!is_dir($jsonRoot)) {
    fwrite(STDERR, "ERROR: no existe el directorio JSON: {$jsonRoot}\n");
    exit(2);
}
if (!is_dir($hlsRoot)) {
    fwrite(STDERR, "ERROR: no existe el directorio HLS: {$hlsRoot}\n");
    exit(2);
}

$hlsRealRoot = realpath($hlsRoot);
if ($hlsRealRoot === false) {
    fwrite(STDERR, "ERROR: no se pudo resolver la ruta HLS.\n");
    exit(2);
}

$albumJsonIds = [];
$referencedJobDirs = [];
$invalidHlsRefs = [];
$badJson = [];

foreach (glob($jsonRoot . '/*/album.json') ?: [] as $jsonFile) {
    $albumId = basename(dirname($jsonFile));
    $albumJsonIds[$albumId] = true;
    $raw = @file_get_contents($jsonFile);
    $album = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($album)) {
        $badJson[] = $jsonFile;
        continue;
    }

    foreach (($album['tracks'] ?? []) as $track) {
        if (!is_array($track)) continue;
        $hls = trim((string)($track['hls'] ?? ''));
        if ($hls === '') continue;

        // Solo aceptar rutas relativas hls_out/<album-dir>/<job-dir>/...m3u8
        $parts = explode('/', str_replace('\\', '/', $hls));
        if (count($parts) !== 4 || $parts[0] !== 'hls_out'
            || $parts[1] === '' || $parts[2] === ''
            || !str_ends_with(strtolower($parts[3]), '.m3u8')
            || in_array('..', $parts, true) || str_contains($hls, "\0")) {
            $invalidHlsRefs[] = $albumId . ': ' . $hls;
            continue;
        }

        $candidate = $hlsRealRoot . DIRECTORY_SEPARATOR . $parts[1]
            . DIRECTORY_SEPARATOR . $parts[2];
        $candidateReal = realpath($candidate);
        if ($candidateReal !== false
            && str_starts_with($candidateReal, $hlsRealRoot . DIRECTORY_SEPARATOR)) {
            $referencedJobDirs[$candidateReal] = true;
        } else {
            $invalidHlsRefs[] = $albumId . ': ' . $hls . ' (ruta no encontrada o fuera de HLS)';
        }
    }
}

$orphanAlbumDirs = [];
$orphanJobDirs = [];
$emptyHlsAlbumDirs = [];
$duplicateHlsAlbumIds = [];
$hlsDirsByAlbumId = [];
$hlsAlbumDirs = glob($hlsRealRoot . '/*', GLOB_ONLYDIR) ?: [];
foreach ($hlsAlbumDirs as $hlsAlbumDir) {
    $hlsAlbumName = basename($hlsAlbumDir);
    $albumId = explode('_', $hlsAlbumName, 2)[0];
    $hlsDirsByAlbumId[$albumId][] = $hlsAlbumDir;
    if (!isset($albumJsonIds[$albumId])) {
        $orphanAlbumDirs[] = $hlsAlbumDir;
        continue;
    }

    $jobDirs = glob($hlsAlbumDir . '/*', GLOB_ONLYDIR) ?: [];
    $entries = @scandir($hlsAlbumDir);
    if (is_array($entries) && count(array_diff($entries, ['.', '..'])) === 0) {
        $emptyHlsAlbumDirs[] = $hlsAlbumDir;
    }
    foreach ($jobDirs as $jobDir) {
        $jobReal = realpath($jobDir);
        if ($jobReal !== false && !isset($referencedJobDirs[$jobReal])) {
            $orphanJobDirs[] = $jobDir;
        }
    }
}

foreach ($hlsDirsByAlbumId as $id => $dirs) {
    if (count($dirs) > 1) {
        $duplicateHlsAlbumIds[] = $id . ': ' . implode(' | ', $dirs);
    }
}

$jsonWithoutHls = [];
foreach (array_keys($albumJsonIds) as $albumId) {
    $found = false;
    foreach ($hlsAlbumDirs as $hlsAlbumDir) {
        if (explode('_', basename($hlsAlbumDir), 2)[0] === $albumId) {
            $found = true;
            break;
        }
    }
    if (!$found) $jsonWithoutHls[] = $jsonRoot . '/' . $albumId . '/album.json';
}

$printSection = static function (string $title, array $items): void {
    echo "\n=== {$title} (" . count($items) . ") ===\n";
    if (!$items) {
        echo "Ninguno.\n";
        return;
    }
    foreach ($items as $item) echo $item . "\n";
};

echo "HLS Music Lab — auditoría de residuos (solo lectura)\n";
echo "JSON: {$jsonRoot}\nHLS:  {$hlsRealRoot}\n";
$printSection('Carpetas HLS de pistas sin referencia en album.json', $orphanJobDirs);
$printSection('Carpetas HLS de álbum sin album.json', $orphanAlbumDirs);
$printSection('Directorios HLS de álbum vacíos (no se borran automáticamente)', $emptyHlsAlbumDirs);
$printSection('IDs con varias carpetas HLS (revisión manual)', $duplicateHlsAlbumIds);
$printSection('album.json sin carpeta HLS correspondiente', $jsonWithoutHls);
$printSection('Referencias HLS inválidas o inexistentes en JSON', $invalidHlsRefs);
$printSection('Archivos album.json con JSON inválido', $badJson);
echo "\nNo se borró ni modificó ningún archivo. Revisa los resultados antes de limpiar manualmente.\n";
