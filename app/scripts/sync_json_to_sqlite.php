<?php
declare(strict_types=1);

/**
 * HLS Music Lab: sincroniza album.json hacia SQLite.
 * Seguro por defecto: solo informa. Para escribir requiere --apply.
 * No elimina álbumes ni canciones que existan únicamente en SQLite.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/lib.php';

$apply = in_array('--apply', $argv, true);
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Uso: php scripts/sync_json_to_sqlite.php [--apply]\n";
    echo "Sin --apply ejecuta una simulación sin escrituras. No elimina filas obsoletas.\n";
    exit(0);
}

$dbPath = getenv('HLS_MUSIC_LAB_SQLITE_DB') ?: '/sparrowfy/database/library.sqlite';
if (!is_file($dbPath) || !is_readable($dbPath) || ($apply && !is_writable($dbPath))) {
    fwrite(STDERR, "ERROR: base SQLite inexistente, ilegible o no escribible: {$dbPath}\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "ERROR: la extensión pdo_sqlite no está disponible.\n");
    exit(1);
}

try {
    $albums = lab_list_albums();
    if (!is_array($albums)) {
        throw new RuntimeException('lab_list_albums() no devolvió una lista válida.');
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    if ((int)$pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
        throw new RuntimeException('No se pudieron activar las claves foráneas en esta conexión.');
    }
    if (!$apply) {
        $pdo->exec('PRAGMA query_only = ON');
    }

    $required = [
        'albums' => ['id','title','artist','cover','cover_layout_json','created_at','matched_album','matched_artist','metadata_json','imported_at','updated_at'],
        'tracks' => ['id','album_id','name','original_name','job_id','hls_path','duration','source_kbps','position','metadata_json','imported_at','updated_at'],
        'track_renditions' => ['track_id','name','bitrate_kbps'],
    ];
    foreach ($required as $table => $columns) {
        $actual = array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
        if (!$actual) throw new RuntimeException("Falta la tabla requerida: {$table}");
        foreach ($columns as $column) {
            if (!in_array($column, $actual, true)) {
                throw new RuntimeException("Falta la columna {$table}.{$column}");
            }
        }
    }

    $jsonAlbumIds = [];
    $jsonTrackIds = [];
    $prepared = [];
    foreach ($albums as $album) {
        if (!is_array($album)) throw new RuntimeException('Se encontró un álbum con formato inválido.');
        $albumId = strtolower(trim((string)($album['id'] ?? '')));
        if (!preg_match('/^[a-f0-9]{16}$/', $albumId)) {
            throw new RuntimeException('ID de álbum inválido: ' . ($albumId ?: '(vacío)'));
        }
        if (isset($jsonAlbumIds[$albumId])) throw new RuntimeException("ID de álbum duplicado en JSON: {$albumId}");
        $jsonAlbumIds[$albumId] = true;
        if (!is_array($album['tracks'] ?? null)) throw new RuntimeException("El álbum {$albumId} no contiene una lista tracks válida.");
        $trackRows = [];
        $position = 0;
        foreach ($album['tracks'] as $track) {
            if (!is_array($track)) throw new RuntimeException("Canción inválida en álbum {$albumId}.");
            $trackId = strtolower(trim((string)($track['id'] ?? '')));
            if (!preg_match('/^[a-f0-9]{16}$/', $trackId)) throw new RuntimeException("ID de canción inválido en álbum {$albumId}: " . ($trackId ?: '(vacío)'));
            if (isset($jsonTrackIds[$trackId])) throw new RuntimeException("ID de canción duplicado en JSON: {$trackId}");
            $jsonTrackIds[$trackId] = true;
            $name = trim((string)($track['name'] ?? ''));
            $hls = trim((string)($track['hls'] ?? ''));
            if ($name === '' || $hls === '') throw new RuntimeException("La canción {$trackId} no tiene name o hls.");
            if (!array_key_exists('duration', $track) || !is_numeric($track['duration']) || (float)$track['duration'] < 0) {
                throw new RuntimeException("Duración inválida para la canción {$trackId}.");
            }
            $renditions = $track['renditions'] ?? [];
            if (!is_array($renditions)) throw new RuntimeException("renditions no es una lista en la canción {$trackId}.");
            $renditions = array_values(array_unique(array_map('strval', $renditions)));
            $trackExtra = $track;
            foreach (['id','name','original','job_id','hls','duration','source_kbps','renditions'] as $key) unset($trackExtra[$key]);
            $trackRows[] = [
                'id' => $trackId,
                'name' => $name,
                'original_name' => isset($track['original']) ? (string)$track['original'] : null,
                'job_id' => isset($track['job_id']) ? (string)$track['job_id'] : null,
                'hls_path' => $hls,
                'duration' => (float)$track['duration'],
                'source_kbps' => isset($track['source_kbps']) && is_numeric($track['source_kbps']) ? (int)$track['source_kbps'] : null,
                'position' => $position++,
                'metadata_json' => $trackExtra ? json_encode($trackExtra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
                'renditions' => $renditions,
            ];
        }
        $albumExtra = $album;
        foreach (['id','title','artist','cover','cover_layout','created_at','matched_album','matched_artist','tracks'] as $key) unset($albumExtra[$key]);
        $layout = $album['cover_layout'] ?? null;
        $prepared[] = [
            'id' => $albumId,
            'title' => trim((string)($album['title'] ?? '')),
            'artist' => trim((string)($album['artist'] ?? '')),
            'cover' => isset($album['cover']) ? (string)$album['cover'] : null,
            'cover_layout_json' => is_array($layout) ? json_encode($layout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            'created_at' => isset($album['created_at']) ? (string)$album['created_at'] : null,
            'matched_album' => isset($album['matched_album']) ? (string)$album['matched_album'] : null,
            'matched_artist' => isset($album['matched_artist']) ? (string)$album['matched_artist'] : null,
            'metadata_json' => $albumExtra ? json_encode($albumExtra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            'tracks' => $trackRows,
        ];
        if ($prepared[count($prepared)-1]['title'] === '' || $prepared[count($prepared)-1]['artist'] === '') {
            throw new RuntimeException("El álbum {$albumId} no tiene title o artist válido.");
        }
    }

    $dbAlbumIds = array_fill_keys($pdo->query('SELECT id FROM albums')->fetchAll(PDO::FETCH_COLUMN), true);
    $dbTrackIds = array_fill_keys($pdo->query('SELECT id FROM tracks')->fetchAll(PDO::FETCH_COLUMN), true);
    $staleAlbums = array_values(array_diff(array_keys($dbAlbumIds), array_keys($jsonAlbumIds)));
    $staleTracks = array_values(array_diff(array_keys($dbTrackIds), array_keys($jsonTrackIds)));

    $albumCount = count($prepared);
    $trackCount = count($jsonTrackIds);
    $renditionCount = array_sum(array_map(static fn(array $a): int => array_sum(array_map(static fn(array $t): int => count($t['renditions']), $a['tracks'])), $prepared));
    echo 'Modo: ' . ($apply ? 'APLICAR CAMBIOS' : 'SIMULACIÓN (sin escrituras)') . "\n";
    echo "JSON: {$albumCount} álbumes, {$trackCount} canciones, {$renditionCount} rendiciones.\n";
    echo 'Solo en SQLite (se conservarán; no se borran): ' . count($staleAlbums) . ' álbumes, ' . count($staleTracks) . " canciones.\n";

    if (!$apply) {
        echo "Validación correcta. Para escribir, haz primero un respaldo y ejecuta con --apply.\n";
        exit(0);
    }

    $pdo->exec('PRAGMA query_only = OFF');
    $pdo->beginTransaction();
    $now = gmdate('Y-m-d\TH:i:s\Z');

    $getAlbum = $pdo->prepare('SELECT id FROM albums WHERE id = ?');
    $insertAlbum = $pdo->prepare('INSERT INTO albums (id,title,artist,cover,cover_layout_json,created_at,matched_album,matched_artist,metadata_json,imported_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $getTrack = $pdo->prepare('SELECT album_id FROM tracks WHERE id = ?');
    $insertTrack = $pdo->prepare('INSERT INTO tracks (id,album_id,name,original_name,job_id,hls_path,duration,source_kbps,position,metadata_json,imported_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $insertRendition = $pdo->prepare('INSERT INTO track_renditions (track_id,name,bitrate_kbps) VALUES (?,?,?) ON CONFLICT(track_id,name) DO NOTHING');

    foreach ($prepared as $album) {
        $getAlbum->execute([$album['id']]);
        $existingAlbumId = $getAlbum->fetchColumn();
        if ($existingAlbumId === false) {
            $insertAlbum->execute([$album['id'],$album['title'],$album['artist'],$album['cover'],$album['cover_layout_json'],$album['created_at'],$album['matched_album'],$album['matched_artist'],$album['metadata_json'],$now,$now]);
        }

        foreach ($album['tracks'] as $track) {
            $getTrack->execute([$track['id']]);
            $existingTrackAlbumId = $getTrack->fetchColumn();
            $values = [$album['id'],$track['name'],$track['original_name'],$track['job_id'],$track['hls_path'],$track['duration'],$track['source_kbps'],$track['position'],$track['metadata_json']];
            if ($existingTrackAlbumId === false) {
                $insertTrack->execute([$track['id'], ...$values, $now, $now]);
            } else {
                if ((string)$existingTrackAlbumId !== $album['id']) {
                    throw new RuntimeException(
                        "La canción {$track['id']} ya existe en SQLite asociada al álbum "
                        . (string)$existingTrackAlbumId
                        . ", pero JSON la asigna al álbum {$album['id']}. No se modificaron sus datos."
                    );
                }
            }

            foreach ($track['renditions'] as $rendition) {
                $bitrate = preg_match('/^(\d+)k$/i', $rendition, $m) ? (int)$m[1] : null;
                $insertRendition->execute([$track['id'], $rendition, $bitrate]);
            }
        }
    }
    $pdo->commit();
    echo "Sincronización completada dentro de una transacción SQLite.\n";
    echo "No se eliminaron álbumes ni canciones que solo existieran en SQLite.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
