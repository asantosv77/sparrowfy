<?php
declare(strict_types=1);

/**
 * Reconciles SQLite to album.json. Dry-run by default. --apply creates a
 * consistent SQLite backup with VACUUM INTO before writing. Never touches JSON/HLS.
 */
$root = dirname(__DIR__);
$albumsDir = $root . '/data/albums';
$dbPath = '/sparrowfy/database/library.sqlite';
$apply = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') $apply = true;
    elseif ($arg === '--dry-run') $apply = false;
    elseif (str_starts_with($arg, '--db=')) $dbPath = substr($arg, 5);
    else { fwrite(STDERR, "Argumento no reconocido: {$arg}\n"); exit(2); }
}

if (!is_dir($albumsDir)) { fwrite(STDERR, "ERROR: no existe {$albumsDir}\n"); exit(2); }
if (!is_file($dbPath)) { fwrite(STDERR, "ERROR: no existe SQLite: {$dbPath}\nNo se creó una base nueva.\n"); exit(2); }
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { fwrite(STDERR, "ERROR: falta PDO_SQLite.\n"); exit(2); }

$validRelPath = static function (string $path): bool {
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) return false;
    if (preg_match('/^[A-Za-z]:/', $path)) return false;
    $parts = explode('/', $path);
    return !in_array('..', $parts, true) && !in_array('.', $parts, true) && str_starts_with($path, 'hls_out/');
};
$files = glob($albumsDir . '/*/album.json') ?: [];
sort($files, SORT_STRING);
$albums = [];
$allTrackIds = [];
$issues = [];
$missingManifests = 0;
$now = gmdate('c');

foreach ($files as $file) {
    $folderId = basename(dirname($file));
    $raw = @file_get_contents($file);
    $a = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($a)) { $issues[] = "JSON inválido: {$file}"; continue; }
    $id = (string)($a['id'] ?? '');
    $title = trim((string)($a['title'] ?? ''));
    if (!preg_match('/^[a-f0-9]{16}$/', $folderId) || $id !== $folderId || $title === '') {
        $issues[] = "ID de carpeta/JSON inválido o título vacío: {$file}"; continue;
    }
    if (isset($albums[$id])) { $issues[] = "Álbum duplicado: {$id}"; continue; }
    $tracks = $a['tracks'] ?? [];
    if (!is_array($tracks)) { $issues[] = "tracks no es una lista en {$file}"; continue; }
    $cleanTracks = [];
    $local = [];
    foreach (array_values($tracks) as $position => $t) {
        if (!is_array($t)) { $issues[] = "{$id}: pista no válida en posición {$position}"; continue; }
        $tid = strtolower(trim((string)($t['id'] ?? '')));
        $name = trim((string)($t['name'] ?? ''));
        $hls = (string)($t['hls'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $tid) || $name === '' || !$validRelPath($hls)) {
            $issues[] = "{$id}: pista en posición {$position} con ID/nombre/ruta inválidos"; continue;
        }
        if (isset($local[$tid]) || isset($allTrackIds[$tid])) { $issues[] = "ID de pista duplicado globalmente: {$tid}"; continue; }
        $local[$tid] = true;
        $allTrackIds[$tid] = $id;
        if (!is_file($root . '/' . $hls)) $missingManifests++;
        $renditions = [];
        foreach (($t['renditions'] ?? []) as $rendition) {
            if (!is_string($rendition) || !preg_match('/^([0-9]{1,3})k$/', $rendition, $m)) {
                $issues[] = "{$id}/{$tid}: rendition inválida"; continue;
            }
            $renditions[$rendition] = (int)$m[1];
        }
        $cleanTracks[] = [
            'id'=>$tid, 'name'=>$name,
            'original_name'=>isset($t['original']) ? (string)$t['original'] : (isset($t['original_name']) ? (string)$t['original_name'] : null),
            'job_id'=>isset($t['job_id']) ? (string)$t['job_id'] : null,
            'hls_path'=>$hls,
            'duration'=>max(0, (float)($t['duration'] ?? 0)),
            'source_kbps'=>isset($t['source_kbps']) ? max(0, (int)$t['source_kbps']) : null,
            'position'=>$position,
            'renditions'=>$renditions,
            'metadata_json'=>json_encode($t, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ];
    }
    $albums[$id] = [
        'id'=>$id, 'title'=>$title, 'artist'=>(string)($a['artist'] ?? ''),
        'cover'=>isset($a['cover']) ? (string)$a['cover'] : null,
        'cover_layout_json'=>isset($a['cover_layout']) ? json_encode($a['cover_layout'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
        'created_at'=>isset($a['created_at']) ? (string)$a['created_at'] : null,
        'matched_album'=>isset($a['matched_album']) ? (string)$a['matched_album'] : null,
        'matched_artist'=>isset($a['matched_artist']) ? (string)$a['matched_artist'] : null,
        'metadata_json'=>json_encode(array_diff_key($a, array_flip(['id','title','artist','cover','cover_layout','created_at','matched_album','matched_artist','tracks'])), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        'tracks'=>$cleanTracks,
    ];
}

if ($issues) {
    fwrite(STDERR, "No se puede reconciliar mientras haya incidencias de estructura:\n - " . implode("\n - ", $issues) . "\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    foreach (['albums','tracks','track_renditions'] as $table) {
        $q = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $q->execute([$table]);
        $exists = $q->fetchColumn();
        $q->closeCursor();
        if (!$exists) throw new RuntimeException("Falta la tabla requerida {$table}; no se aplicó ninguna modificación.");
    }
    $dbAlbums = array_map('strval', array_column($pdo->query('SELECT id FROM albums')->fetchAll(), 'id'));
    $dbTracks = array_map('strval', array_column($pdo->query('SELECT id FROM tracks')->fetchAll(), 'id'));
    $jsonAlbums = array_keys($albums); sort($jsonAlbums, SORT_STRING);
    $jsonTracks = array_keys($allTrackIds); sort($jsonTracks, SORT_STRING);
    sort($dbAlbums, SORT_STRING); sort($dbTracks, SORT_STRING);
    $missingAlbums = array_values(array_diff($jsonAlbums, $dbAlbums));
    $staleAlbums = array_values(array_diff($dbAlbums, $jsonAlbums));
    $missingTracks = array_values(array_diff($jsonTracks, $dbTracks));
    $staleTracks = array_values(array_diff($dbTracks, $jsonTracks));

    echo "HLS Music Lab — reconciliación JSON → SQLite\n";
    echo $apply ? "MODO: APPLY\n" : "MODO: DRY-RUN (sin escrituras)\n";
    echo 'JSON: ' . count($albums) . ' álbumes, ' . count($allTrackIds) . " pistas\n";
    echo 'SQLite antes: ' . count($dbAlbums) . ' álbumes, ' . count($dbTracks) . " pistas\n";
    echo 'Álbumes nuevos en SQLite: ' . count($missingAlbums) . "\n - " . (implode("\n - ", $missingAlbums) ?: 'ninguno') . "\n";
    echo 'Álbumes sobrantes en SQLite: ' . count($staleAlbums) . "\n - " . (implode("\n - ", $staleAlbums) ?: 'ninguno') . "\n";
    echo 'Pistas nuevas en SQLite: ' . count($missingTracks) . "\n - " . (implode("\n - ", $missingTracks) ?: 'ninguna') . "\n";
    echo 'Pistas sobrantes en SQLite: ' . count($staleTracks) . "\n - " . (implode("\n - ", $staleTracks) ?: 'ninguna') . "\n";
    echo "Manifiestos HLS ausentes según JSON: {$missingManifests}\n";
    if (!$apply) { echo "\nNo se modificó nada. Revisa el informe y vuelve a ejecutar con --apply para aplicar el espejo.\n"; exit(0); }

    if (!is_writable($dbPath) || !is_writable(dirname($dbPath))) throw new RuntimeException('No hay permisos de escritura en SQLite o su directorio.');
    $backup = $dbPath . '.pre-reconcile-' . gmdate('Ymd-His') . '.bak';
    if (file_exists($backup)) throw new RuntimeException('El archivo de respaldo ya existe: ' . $backup);
    // VACUUM INTO produce una copia consistente incluso si SQLite usa WAL.
    $pdo->exec('VACUUM INTO ' . $pdo->quote($backup));
    if (!is_file($backup) || filesize($backup) <= 0) throw new RuntimeException('No se pudo verificar el respaldo previo.');
    @chmod($backup, 0640);

    $pdo->beginTransaction();
    $albumStmt = $pdo->prepare('INSERT INTO albums (id,title,artist,cover,cover_layout_json,created_at,matched_album,matched_artist,metadata_json,imported_at,updated_at) VALUES (:id,:title,:artist,:cover,:layout,:created,:matched_album,:matched_artist,:metadata,:imported,:updated) ON CONFLICT(id) DO UPDATE SET title=excluded.title,artist=excluded.artist,cover=excluded.cover,cover_layout_json=excluded.cover_layout_json,created_at=excluded.created_at,matched_album=excluded.matched_album,matched_artist=excluded.matched_artist,metadata_json=excluded.metadata_json,updated_at=excluded.updated_at');
    $trackStmt = $pdo->prepare('INSERT INTO tracks (id,album_id,name,original_name,job_id,hls_path,duration,source_kbps,position,metadata_json,imported_at,updated_at) VALUES (:id,:album_id,:name,:original,:job_id,:hls,:duration,:kbps,:position,:metadata,:imported,:updated) ON CONFLICT(id) DO UPDATE SET album_id=excluded.album_id,name=excluded.name,original_name=excluded.original_name,job_id=excluded.job_id,hls_path=excluded.hls_path,duration=excluded.duration,source_kbps=excluded.source_kbps,position=excluded.position,metadata_json=excluded.metadata_json,updated_at=excluded.updated_at');
    $renditionStmt = $pdo->prepare('INSERT INTO track_renditions(track_id,name,bitrate_kbps) VALUES (?,?,?) ON CONFLICT(track_id,name) DO UPDATE SET bitrate_kbps=excluded.bitrate_kbps');
    foreach ($albums as $a) {
        $albumStmt->execute([':id'=>$a['id'], ':title'=>$a['title'], ':artist'=>$a['artist'], ':cover'=>$a['cover'], ':layout'=>$a['cover_layout_json'], ':created'=>$a['created_at'], ':matched_album'=>$a['matched_album'], ':matched_artist'=>$a['matched_artist'], ':metadata'=>$a['metadata_json'], ':imported'=>$now, ':updated'=>$now]);
        foreach ($a['tracks'] as $t) {
            $trackStmt->execute([':id'=>$t['id'], ':album_id'=>$a['id'], ':name'=>$t['name'], ':original'=>$t['original_name'], ':job_id'=>$t['job_id'], ':hls'=>$t['hls_path'], ':duration'=>$t['duration'], ':kbps'=>$t['source_kbps'], ':position'=>$t['position'], ':metadata'=>$t['metadata_json'], ':imported'=>$now, ':updated'=>$now]);
            $pdo->prepare('DELETE FROM track_renditions WHERE track_id=?')->execute([$t['id']]);
            foreach ($t['renditions'] as $rendition=>$kbps) $renditionStmt->execute([$t['id'], $rendition, $kbps]);
        }
    }
    $keepTrack = $pdo->prepare('DELETE FROM track_renditions WHERE track_id=?');
    $delTrack = $pdo->prepare('DELETE FROM tracks WHERE id=?');
    foreach ($dbTracks as $id) {
        if (!isset($allTrackIds[$id])) { $keepTrack->execute([$id]); $delTrack->execute([$id]); }
    }
    $delAlbum = $pdo->prepare('DELETE FROM albums WHERE id=?');
    foreach ($dbAlbums as $id) if (!isset($albums[$id])) $delAlbum->execute([$id]);
    $fkErrors = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    if ($fkErrors) throw new RuntimeException('La comprobación de claves foráneas encontró errores; se revertirá la reconciliación.');
    $pdo->commit();
    @chmod($backup, 0640);
    echo "\nRECONCILIACIÓN COMPLETADA. No se modificaron JSON ni HLS.\nRespaldo: {$backup}\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(2);
}
