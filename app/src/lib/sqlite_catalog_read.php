<?php
declare(strict_types=1);

/**
 * HLS Music Lab: adaptador de SOLO LECTURA para reconstruir el formato legacy
 * de album.json desde SQLite. No se activa automáticamente ni modifica archivos.
 * Requiere que lib.php ya haya definido LAB_ROOT.
 */

if (!defined('LAB_ROOT')) {
    throw new RuntimeException('Carga lib.php antes de sqlite_catalog_read.php');
}

function lab_sqlite_catalog_db_path(): string
{
    // Puede sobreescribirse desde el entorno si en el futuro cambia el montaje.
    $env = getenv('HLS_MUSIC_LAB_SQLITE_DB');
    return is_string($env) && $env !== '' ? $env : '/sparrowfy/database/library.sqlite';
}

function lab_sqlite_catalog_pdo(): PDO
{
    $path = lab_sqlite_catalog_db_path();
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('SQLite no existe o no es legible: ' . $path);
    }
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('La extensión pdo_sqlite no está disponible.');
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA query_only = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

/** Devuelve los álbumes en una forma compatible con lab_list_albums()/album.json. */
function lab_sqlite_list_albums(?string $onlyId=null): array
{
    $pdo = lab_sqlite_catalog_pdo();
    $query = $pdo->prepare('SELECT * FROM albums WHERE (:id IS NULL OR id=:id) ORDER BY COALESCE(created_at, imported_at) DESC, id');
    $query->execute([':id'=>$onlyId]);$albums=$query->fetchAll();
    $tracksStmt = $pdo->prepare('SELECT * FROM tracks WHERE album_id = ? ORDER BY position, id');
    $rendStmt = $pdo->prepare('SELECT name FROM track_renditions WHERE track_id = ? ORDER BY bitrate_kbps, name');
    $result = [];

    foreach ($albums as $row) {
        $extra = [];
        if (is_string($row['metadata_json'] ?? null) && $row['metadata_json'] !== '') {
            $decoded = json_decode($row['metadata_json'], true);
            if (is_array($decoded)) $extra = $decoded;
        }
        $album = array_merge($extra, [
            'id' => (string)$row['id'],
            'title' => (string)$row['title'],
            'artist' => (string)($row['artist'] ?? ''),
            'cover' => $row['cover'] !== null ? (string)$row['cover'] : null,
            'created_at' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
        ]);
        if (is_string($row['cover_layout_json'] ?? null) && $row['cover_layout_json'] !== '') {
            $layout = json_decode($row['cover_layout_json'], true);
            if (is_array($layout)) $album['cover_layout'] = $layout;
        }
        if ($row['matched_album'] !== null) $album['matched_album'] = (string)$row['matched_album'];
        if ($row['matched_artist'] !== null) $album['matched_artist'] = (string)$row['matched_artist'];

        $tracksStmt->execute([(string)$row['id']]);
        $tracks = [];
        foreach ($tracksStmt->fetchAll() as $t) {
            $track = [];
            if (is_string($t['metadata_json'] ?? null) && $t['metadata_json'] !== '') {
                $decodedTrack = json_decode($t['metadata_json'], true);
                if (is_array($decodedTrack)) $track = $decodedTrack;
            }
            // Los campos normalizados en SQLite prevalecen sobre la copia metadata_json.
            $track = array_merge($track, [
                'id' => (string)$t['id'],
                'name' => (string)$t['name'],
                'hls' => (string)$t['hls_path'],
                'duration' => (float)$t['duration'],
            ]);
            if ($t['original_name'] !== null) $track['original'] = (string)$t['original_name'];
            if ($t['job_id'] !== null) $track['job_id'] = (string)$t['job_id'];
            if ($t['source_kbps'] !== null) $track['source_kbps'] = (int)$t['source_kbps'];

            $rendStmt->execute([(string)$t['id']]);
            $renditions = array_map(static fn(array $r): string => (string)$r['name'], $rendStmt->fetchAll());
            // No inventar renditions si la tabla está vacía; conservar metadata_json si ya la contiene.
            if ($renditions) $track['renditions'] = $renditions;
            elseif (!isset($track['renditions']) || !is_array($track['renditions'])) $track['renditions'] = [];
            $tracks[] = $track;
        }
        $album['tracks'] = ($album['album_type']??'')==='online_links' ? ($extra['tracks']??[]) : $tracks;
        $result[] = $album;
    }
    return $result;
}

/** Carga un álbum por ID desde SQLite; null si no existe. */
function lab_sqlite_load_album(string $id): ?array
{
    $id = strtolower(trim($id));
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) return null;
    foreach (lab_sqlite_list_albums($id) as $album) {
        if (($album['id'] ?? null) === $id) return $album;
    }
    return null;
}
