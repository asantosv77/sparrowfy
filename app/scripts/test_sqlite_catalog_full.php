<?php
declare(strict_types=1);

/**
 * Comparación ampliada JSON vs SQLite.
 * Solo lectura: no escribe en JSON ni en SQLite.
 */
$root = dirname(__DIR__);

require_once $root . '/lib.php';
require_once $root . '/lib/sqlite_catalog_read.php';

function full_test_fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(2);
}

/** Ordena claves asociativas, pero conserva el orden de las listas. */
function full_test_normalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $item) {
        $value[$key] = full_test_normalize($item);
    }

    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return $value;
}

function full_test_catalog(array $albums): array
{
    $result = [];

    $albumKnown = [
        'id', 'title', 'artist', 'cover', 'cover_layout',
        'created_at', 'matched_album', 'matched_artist', 'tracks',
    ];

    $trackKnown = [
        'id', 'name', 'original', 'job_id', 'hls',
        'duration', 'source_kbps', 'renditions',
    ];

    foreach ($albums as $album) {
        if (!is_array($album)) {
            throw new RuntimeException('Se encontró un álbum inválido.');
        }

        $id = (string)($album['id'] ?? '');

        if ($id === '' || isset($result[$id])) {
            throw new RuntimeException(
                'ID de álbum vacío o duplicado: ' . $id
            );
        }

        $extraAlbum = array_diff_key(
            $album,
            array_flip($albumKnown)
        );

        $tracks = [];

        foreach ((array)($album['tracks'] ?? []) as $track) {
            if (!is_array($track)) {
                throw new RuntimeException(
                    "Pista inválida en álbum {$id}"
                );
            }

            $trackId = (string)($track['id'] ?? '');

            if ($trackId === '' || isset($tracks[$trackId])) {
                throw new RuntimeException(
                    "ID de pista vacío o duplicado: {$trackId}"
                );
            }

            $extraTrack = array_diff_key(
                $track,
                array_flip($trackKnown)
            );

            $normalizedTrack = [
                'id' => $trackId,
                'fields' => [],
                'extra' => full_test_normalize($extraTrack),
            ];

            foreach (
                ['name', 'original', 'job_id', 'hls',
                 'duration', 'source_kbps', 'renditions']
                as $field
            ) {
                if (array_key_exists($field, $track)) {
                    $value = $track[$field];

                    if ($field === 'duration' && $value !== null) {
                        $value = round((float)$value, 6);
                    }

                    if ($field === 'source_kbps' && $value !== null) {
                        $value = (int)$value;
                    }

                    if ($field === 'renditions' && is_array($value)) {
                        $value = array_values(
                            array_map('strval', $value)
                        );
                        sort($value, SORT_STRING);
                    }

                    $normalizedTrack['fields'][$field] = $value;
                }
            }

            $normalizedTrack['fields'] =
                full_test_normalize($normalizedTrack['fields']);

            $tracks[$trackId] = $normalizedTrack;
        }

        // Conservar el orden de las pistas: es la posición de reproducción.
        $orderedTracks = array_values($tracks);

        $normalizedAlbum = [
            'id' => $id,
            'fields' => [],
            'extra' => full_test_normalize($extraAlbum),
            'tracks' => $orderedTracks,
        ];

        foreach (
            ['title', 'artist', 'cover', 'cover_layout',
             'created_at', 'matched_album', 'matched_artist']
            as $field
        ) {
            if (array_key_exists($field, $album)) {
                $normalizedAlbum['fields'][$field] = $album[$field];
            }
        }

        $normalizedAlbum['fields'] =
            full_test_normalize($normalizedAlbum['fields']);

        $result[$id] = $normalizedAlbum;
    }

    ksort($result, SORT_STRING);

    return $result;
}

try {
    $jsonAlbums = lab_list_albums();
    $sqliteAlbums = lab_sqlite_list_albums();

    $json = full_test_catalog($jsonAlbums);
    $sqlite = full_test_catalog($sqliteAlbums);
} catch (Throwable $e) {
    full_test_fail($e->getMessage());
}

$errors = [];

$allAlbumIds = array_unique(array_merge(
    array_keys($json),
    array_keys($sqlite)
));

foreach ($allAlbumIds as $albumId) {
    if (!isset($json[$albumId])) {
        $errors[] = "Álbum {$albumId}: solo en SQLite";
        continue;
    }

    if (!isset($sqlite[$albumId])) {
        $errors[] = "Álbum {$albumId}: solo en JSON";
        continue;
    }

    foreach (['fields', 'extra'] as $section) {
        if ($json[$albumId][$section] !== $sqlite[$albumId][$section]) {
            $errors[] =
                "Álbum {$albumId}: diferencias en {$section}";
        }
    }

    $jsonTracks = $json[$albumId]['tracks'];
    $sqliteTracks = $sqlite[$albumId]['tracks'];

    if (count($jsonTracks) !== count($sqliteTracks)) {
        $errors[] =
            "Álbum {$albumId}: cantidad de pistas distinta";
    }

    $jsonIds = array_column($jsonTracks, 'id');
    $sqliteIds = array_column($sqliteTracks, 'id');

    if ($jsonIds !== $sqliteIds) {
        $errors[] =
            "Álbum {$albumId}: orden o IDs de pistas diferentes";
    }

    $sqliteById = [];

    foreach ($sqliteTracks as $track) {
        $sqliteById[$track['id']] = $track;
    }

    foreach ($jsonTracks as $track) {
        $trackId = $track['id'];

        if (!isset($sqliteById[$trackId])) {
            $errors[] =
                "Pista {$trackId}: falta en SQLite (álbum {$albumId})";
            continue;
        }

        $dbTrack = $sqliteById[$trackId];

        foreach (['fields', 'extra'] as $section) {
            if ($track[$section] !== $dbTrack[$section]) {
                $errors[] =
                    "Pista {$trackId}: diferencias en {$section}";
            }
        }
    }
}

printf("HLS Music Lab — prueba ampliada JSON vs SQLite\n");
printf("Modo: SOLO LECTURA\n");
printf("Álbumes JSON: %d\n", count($json));
printf("Álbumes SQLite: %d\n", count($sqlite));

printf(
    "Pistas JSON: %d\n",
    array_sum(array_map(
        static fn(array $a): int => count($a['tracks']),
        $json
    ))
);

printf(
    "Pistas SQLite: %d\n",
    array_sum(array_map(
        static fn(array $a): int => count($a['tracks']),
        $sqlite
    ))
);

printf("Diferencias detectadas: %d\n", count($errors));

foreach (array_slice($errors, 0, 100) as $error) {
    echo " - {$error}\n";
}

if (count($errors) > 100) {
    echo " - ... más diferencias omitidas\n";
}

if ($errors) {
    echo "\nRESULTADO: NO APTO todavía.\n";
    exit(1);
}

echo "\nRESULTADO: paridad ampliada confirmada.\n";
