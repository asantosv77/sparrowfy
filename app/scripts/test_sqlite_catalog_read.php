<?php
declare(strict_types=1);

/** Prueba de paridad JSON vs SQLite; solo lectura. */
$root = dirname(__DIR__);
$albumsDir = $root . '/data/albums';
require_once $root . '/lib.php';
require_once $root . '/lib/sqlite_catalog_read.php';

function fail_test(string $message): never {
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(2);
}

if (!is_dir($albumsDir)) fail_test('No existe el directorio JSON: ' . $albumsDir);
try {
    $jsonAlbums = lab_list_albums();
    $dbAlbums = lab_sqlite_list_albums();
} catch (Throwable $e) {
    fail_test($e->getMessage());
}

$index = static function(array $albums): array {
    $out = [];
    foreach ($albums as $a) {
        $id = (string)($a['id'] ?? '');
        if ($id === '' || isset($out[$id])) continue;
        $tracks = [];
        foreach ((array)($a['tracks'] ?? []) as $t) {
            if (!is_array($t)) continue;
            $tid = (string)($t['id'] ?? '');
            if ($tid === '') continue;
            $tracks[$tid] = [
                'name' => (string)($t['name'] ?? ''),
                'hls' => (string)($t['hls'] ?? ''),
                'duration' => round((float)($t['duration'] ?? 0), 3),
                'renditions' => array_values(array_map('strval', (array)($t['renditions'] ?? []))),
            ];
            sort($tracks[$tid]['renditions'], SORT_STRING);
        }
        $out[$id] = [
            'title' => (string)($a['title'] ?? ''),
            'artist' => (string)($a['artist'] ?? ''),
            'cover' => $a['cover'] ?? null,
            'tracks' => $tracks,
        ];
        ksort($out[$id]['tracks'], SORT_STRING);
    }
    ksort($out, SORT_STRING);
    return $out;
};

$j = $index($jsonAlbums);
$d = $index($dbAlbums);
$errors = [];
foreach (array_unique(array_merge(array_keys($j), array_keys($d))) as $id) {
    if (!isset($j[$id])) { $errors[] = "Álbum {$id}: solo en SQLite"; continue; }
    if (!isset($d[$id])) { $errors[] = "Álbum {$id}: solo en JSON"; continue; }
    foreach (['title','artist','cover'] as $field) {
        if ($j[$id][$field] !== $d[$id][$field]) $errors[] = "Álbum {$id}: campo {$field} distinto";
    }
    $jt = $j[$id]['tracks']; $dt = $d[$id]['tracks'];
    foreach (array_diff(array_keys($jt), array_keys($dt)) as $tid) $errors[] = "Pista {$tid}: solo en JSON (álbum {$id})";
    foreach (array_diff(array_keys($dt), array_keys($jt)) as $tid) $errors[] = "Pista {$tid}: solo en SQLite (álbum {$id})";
    foreach (array_intersect(array_keys($jt), array_keys($dt)) as $tid) {
        foreach (['name','hls','duration','renditions'] as $field) {
            if ($jt[$tid][$field] !== $dt[$tid][$field]) $errors[] = "Pista {$tid}: campo {$field} distinto";
        }
    }
}

printf("HLS Music Lab — prueba de lectura SQLite (solo lectura)\n");
printf("JSON: %d álbumes, %d pistas\n", count($j), array_sum(array_map(static fn($a) => count($a['tracks']), $j)));
printf("SQLite: %d álbumes, %d pistas\n", count($d), array_sum(array_map(static fn($a) => count($a['tracks']), $d)));
printf("Diferencias: %d\n", count($errors));
foreach (array_slice($errors, 0, 100) as $error) echo " - {$error}\n";
if (count($errors) > 100) echo ' - ... más diferencias omitidas\n';
if ($errors) {
    echo "\nRESULTADO: NO APTO para activar lecturas SQLite. No se modificó nada.\n";
    exit(1);
}
echo "\nRESULTADO: paridad básica confirmada. No se modificó nada.\n";
