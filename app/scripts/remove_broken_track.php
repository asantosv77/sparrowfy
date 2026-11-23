<?php
declare(strict_types=1);

/**
 * Safe CLI cleanup for the broken track shown in the HLS Music Lab screenshot.
 * Default: dry-run. Use --apply to back up album.json, remove exactly one track
 * matching the expected job suffix, and delete only its HLS job directory.
 */
$audioRoot = realpath(__DIR__ . '/..');
if ($audioRoot === false) {
    fwrite(STDERR, "ERROR: no se pudo resolver el directorio audio.\n");
    exit(1);
}
$albumId = '00d32ebc149bdfbe';
$jobSuffix = '20261027_192839_902b6b';
$albumJson = $audioRoot . '/data/albums/' . $albumId . '/album.json';
$apply = in_array('--apply', $argv, true);

if (!is_file($albumJson)) {
    fwrite(STDERR, "ERROR: no existe $albumJson\n");
    exit(1);
}
$raw = file_get_contents($albumJson);
$data = json_decode((string)$raw, true);
if (!is_array($data) || !isset($data['tracks']) || !is_array($data['tracks'])) {
    fwrite(STDERR, "ERROR: album.json no contiene una lista tracks válida; no se modificó nada.\n");
    exit(1);
}
$matches = [];
foreach ($data['tracks'] as $i => $track) {
    $job = (string)($track['job_id'] ?? '');
    $hls = (string)($track['hls'] ?? '');
    if (str_contains($job, $jobSuffix) || str_contains($hls, $jobSuffix)) {
        $matches[] = ['index' => $i, 'track' => $track];
    }
}
if (count($matches) !== 1) {
    fwrite(STDERR, "ERROR: se esperaba encontrar exactamente 1 registro con sufijo $jobSuffix; encontrados: " . count($matches) . "\n");
    foreach ($matches as $m) {
        fwrite(STDERR, " - " . ($m['track']['name'] ?? '(sin nombre)') . " | job_id=" . ($m['track']['job_id'] ?? '') . "\n");
    }
    exit(1);
}
$m = $matches[0];
$track = $m['track'];
$hls = str_replace('\\', '/', (string)($track['hls'] ?? ''));
if ($hls === '' || str_starts_with($hls, '/') || preg_match('~(^|/)\.\.(/|$)~', $hls)) {
    fwrite(STDERR, "ERROR: ruta HLS ausente o insegura; no se modificó nada.\n");
    exit(1);
}
$prefix = 'hls_out/' . $albumId . '_';
if (!str_starts_with($hls, $prefix) || !str_ends_with($hls, '/master.m3u8')) {
    fwrite(STDERR, "ERROR: ruta HLS inesperada, no coincide con el álbum esperado: $hls\n");
    exit(1);
}
$relativeDir = dirname($hls);
$hlsBase = realpath($audioRoot . '/hls_out');
if ($hlsBase === false) {
    fwrite(STDERR, "ERROR: no existe el directorio hls_out; no se modificó nada.\n");
    exit(1);
}
$targetDir = $audioRoot . '/' . $relativeDir;
$parent = realpath(dirname($targetDir));
if ($parent === false || !str_starts_with($parent . DIRECTORY_SEPARATOR, $hlsBase . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "ERROR: el directorio de destino no está dentro de hls_out; no se modificó nada.\n");
    exit(1);
}

echo "Registro encontrado:\n";
echo " Álbum: " . ($data['title'] ?? $albumId) . " ($albumId)\n";
echo " Pista: " . ($track['name'] ?? '(sin nombre)') . "\n";
echo " Job ID: " . ($track['job_id'] ?? '(sin job_id)') . "\n";
echo " HLS: $hls\n";
echo " Directorio a eliminar: $targetDir\n";
echo " Modo: " . ($apply ? "APLICAR CAMBIOS" : "SIMULACIÓN (no modifica nada)") . "\n";

if (!$apply) {
    echo "\nSi los datos anteriores identifican la pista correcta, vuelve a ejecutar con --apply.\n";
    exit(0);
}
if (!is_writable($albumJson) || !is_writable(dirname($albumJson))) {
    fwrite(STDERR, "ERROR: no hay permisos de escritura en album.json o su directorio.\n");
    exit(1);
}
$backup = $albumJson . '.bak.' . date('Ymd_His');
if (!copy($albumJson, $backup)) {
    fwrite(STDERR, "ERROR: no se pudo crear copia de seguridad: $backup\n");
    exit(1);
}
unset($data['tracks'][$m['index']]);
$data['tracks'] = array_values($data['tracks']);
$encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($encoded === false || file_put_contents($albumJson, $encoded . PHP_EOL, LOCK_EX) === false) {
    @copy($backup, $albumJson);
    fwrite(STDERR, "ERROR: no se pudo actualizar album.json; se intentó restaurar la copia.\n");
    exit(1);
}
$removeTree = function (string $dir) use (&$removeTree): bool {
    if (!is_dir($dir) || is_link($dir)) return true;
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) return false;
        } elseif (is_dir($path)) {
            if (!$removeTree($path)) return false;
        }
    }
    return rmdir($dir);
};
if (is_dir($targetDir)) {
    if (!$removeTree($targetDir)) {
        fwrite(STDERR, "AVISO: el registro se quitó y la copia existe ($backup), pero no se pudo borrar completamente el directorio HLS.\n");
        exit(2);
    }
    echo "Directorio HLS eliminado.\n";
} else {
    echo "El directorio HLS ya no existía; se quitó el registro igualmente.\n";
}
echo "Registro eliminado de album.json.\nCopia de seguridad: $backup\n";
