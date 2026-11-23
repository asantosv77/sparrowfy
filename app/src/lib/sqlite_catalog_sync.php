<?php
declare(strict_types=1);

/** Sincroniza un álbum JSON a SQLite sin borrar filas ajenas al álbum. */
function lab_sqlite_sync_album(array $album, ?PDO $sharedPdo = null): void
{
    $dbPath = getenv('HLS_MUSIC_LAB_SQLITE_DB') ?: '/sparrowfy/database/library.sqlite';
    if (!is_file($dbPath) || !is_readable($dbPath) || !is_writable($dbPath)) {
        throw new RuntimeException('SQLite no existe, no es legible o no es escribible: ' . $dbPath);
    }
    if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('La extensión pdo_sqlite no está disponible.');
    $id = strtolower(trim((string)($album['id'] ?? '')));
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) throw new RuntimeException('ID de álbum inválido para sincronización SQLite.');
    $tracks = $album['tracks'] ?? null;
    if (!is_array($tracks)) throw new RuntimeException('El álbum no contiene una lista de pistas válida.');

    $pdo = $sharedPdo ?? new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]);
    if ($sharedPdo === null) $pdo->exec('PRAGMA busy_timeout = 15000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    foreach (['albums','tracks','track_renditions'] as $table) {
        $q = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $q->execute([$table]);
        if (!$q->fetchColumn()) throw new RuntimeException("Falta la tabla SQLite requerida: {$table}");
        $q->closeCursor();
    }
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $layout = $album['cover_layout'] ?? null;
    $albumExtra = $album;
    $online=($album['album_type']??'')==='online_links';
    foreach (['id','title','artist','cover','cover_layout','created_at','matched_album','matched_artist','tracks'] as $key) unset($albumExtra[$key]);
    if($online){$albumExtra['tracks']=$tracks;$tracks=[];}
    $jsonOrNull = static fn($v) => is_array($v) && $v ? json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $a = $pdo->prepare('INSERT INTO albums (id,title,artist,cover,cover_layout_json,created_at,matched_album,matched_artist,metadata_json,imported_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET title=excluded.title,artist=excluded.artist,cover=excluded.cover,cover_layout_json=excluded.cover_layout_json,created_at=excluded.created_at,matched_album=excluded.matched_album,matched_artist=excluded.matched_artist,metadata_json=excluded.metadata_json,updated_at=excluded.updated_at');
        $a->execute([$id, trim((string)($album['title'] ?? '')), (string)($album['artist'] ?? ''), isset($album['cover']) ? (string)$album['cover'] : null, $jsonOrNull($layout), isset($album['created_at']) ? (string)$album['created_at'] : null, isset($album['matched_album']) ? (string)$album['matched_album'] : null, isset($album['matched_artist']) ? (string)$album['matched_artist'] : null, $jsonOrNull($albumExtra), $now, $now]);
        $tStmt = $pdo->prepare('INSERT INTO tracks (id,album_id,name,original_name,job_id,hls_path,duration,source_kbps,position,metadata_json,imported_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET album_id=excluded.album_id,name=excluded.name,original_name=excluded.original_name,job_id=excluded.job_id,hls_path=excluded.hls_path,duration=excluded.duration,source_kbps=excluded.source_kbps,position=excluded.position,metadata_json=excluded.metadata_json,updated_at=excluded.updated_at');
        $delR = $pdo->prepare('DELETE FROM track_renditions WHERE track_id=?');
        $rStmt = $pdo->prepare('INSERT INTO track_renditions (track_id,name,bitrate_kbps) VALUES (?,?,?) ON CONFLICT(track_id,name) DO UPDATE SET bitrate_kbps=excluded.bitrate_kbps');
        foreach (array_values($tracks) as $position => $t) {
            if (!is_array($t)) throw new RuntimeException("Pista inválida en posición {$position}.");
            $tid = strtolower(trim((string)($t['id'] ?? '')));
            $name = trim((string)($t['name'] ?? ''));
            $hls = trim((string)($t['hls'] ?? ''));
            if (!preg_match('/^[a-f0-9]{16}$/', $tid) || $name === '' || $hls === '') throw new RuntimeException("Pista con ID, nombre o ruta HLS inválidos en posición {$position}.");
            if (!str_starts_with($hls, 'hls_out/') || str_contains($hls, '..') || str_contains($hls, '\\')) throw new RuntimeException("Ruta HLS inválida para la pista {$tid}.");
            $extra = $t;
            foreach (['id','name','original','job_id','hls','duration','source_kbps','renditions'] as $key) unset($extra[$key]);
            $tStmt->execute([$tid,$id,$name,isset($t['original'])?(string)$t['original']:null,isset($t['job_id'])?(string)$t['job_id']:null,$hls,max(0,(float)($t['duration']??0)),isset($t['source_kbps'])?(int)$t['source_kbps']:null,$position,$jsonOrNull($extra),$now,$now]);
            $delR->execute([$tid]);
            foreach (array_values(array_unique(array_map('strval', is_array($t['renditions']??null)?$t['renditions']:[]))) as $rendition) {
                $kbps = preg_match('/^(\d+)k$/i', $rendition, $m) ? (int)$m[1] : null;
                $rStmt->execute([$tid,$rendition,$kbps]);
            }
        }
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
