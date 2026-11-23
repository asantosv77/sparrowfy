<?php
declare(strict_types=1);

/**
 * Colecciones favoritas: atajo a una colección completa.
 *
 * Solo se guarda el identificador del album; el contenido se lee siempre del
 * catálogo, así que los cambios de la colección se ven al instante y nunca se
 * duplican pistas en la tabla de favoritos de canciones.
 *
 * No se declaran claves foráneas a propósito: las conexiones web no activan
 * PRAGMA foreign_keys, de modo que el borrado se limpia de forma explícita
 * desde el flujo de eliminación de colecciones.
 */

function sf_fav_albums_table_exists(PDO $p): bool {
    $q = $p->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name='favorite_albums'");
    $q->execute();
    return (bool)$q->fetchColumn();
}

function sf_fav_albums_schema(PDO $p): void {
    if (sf_fav_albums_table_exists($p)) return;
    $p->exec("CREATE TABLE IF NOT EXISTS favorite_albums(user_id TEXT NOT NULL,album_id TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(user_id,album_id))");
    $p->exec('CREATE INDEX IF NOT EXISTS idx_favorite_albums_user ON favorite_albums(user_id,created_at DESC)');
    $p->exec('CREATE INDEX IF NOT EXISTS idx_favorite_albums_album ON favorite_albums(album_id)');
}

/** Filas del usuario, de la más reciente a la más antigua. */
function sf_fav_albums_rows(PDO $p, string $uid): array {
    sf_fav_albums_schema($p);
    $q = $p->prepare('SELECT album_id,created_at FROM favorite_albums WHERE user_id=? ORDER BY created_at DESC,album_id');
    $q->execute([$uid]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function sf_fav_albums_ids(PDO $p, string $uid): array {
    return array_values(array_map(static fn(array $r): string => (string)$r['album_id'], sf_fav_albums_rows($p, $uid)));
}

/** Quita la colección de los favoritos de todos. Se usa dentro del borrado. */
function sf_fav_albums_remove(PDO $p, string $albumId): int {
    if (!preg_match('/^[a-f0-9]{16}$/D', $albumId)) return 0;
    if (!sf_fav_albums_table_exists($p)) return 0;
    $d = $p->prepare('DELETE FROM favorite_albums WHERE album_id=?');
    $d->execute([$albumId]);
    return $d->rowCount();
}

/** Indica si la colección aún conserva alguna pista visible (propias o reutilizadas). */
function sf_fav_albums_has_tracks(PDO $p, string $albumId): bool {
    if (!preg_match('/^[a-f0-9]{16}$/D', $albumId)) return false;
    try {
        $q = $p->prepare('SELECT 1 FROM tracks t WHERE t.album_id=? OR EXISTS(SELECT 1 FROM sf_album_track_refs r WHERE r.track_id=t.id AND r.source_album_id=t.album_id AND r.album_id=?) LIMIT 1');
        $q->execute([$albumId, $albumId]);
        return (bool)$q->fetchColumn();
    } catch (Throwable $e) {
        $q = $p->prepare('SELECT 1 FROM tracks WHERE album_id=? LIMIT 1');
        $q->execute([$albumId]);
        return (bool)$q->fetchColumn();
    }
}

function sf_fav_albums_count(PDO $p, string $albumId): int {
    if (!preg_match('/^[a-f0-9]{16}$/D', $albumId)) return 0;
    if (!sf_fav_albums_table_exists($p)) return 0;
    $q = $p->prepare('SELECT COUNT(*) FROM favorite_albums WHERE album_id=?');
    $q->execute([$albumId]);
    return (int)$q->fetchColumn();
}

/** Nombres (máximo $limit) de quienes tienen la colección en favoritos. */
function sf_fav_albums_usernames(PDO $p, string $albumId, int $limit = 8): array {
    if (!preg_match('/^[a-f0-9]{16}$/D', $albumId)) return [];
    if (!sf_fav_albums_table_exists($p)) return [];
    $limit = max(1, min(50, $limit));
    $q = $p->prepare('SELECT u.username FROM favorite_albums f JOIN users u ON u.id=f.user_id WHERE f.album_id=? ORDER BY u.username COLLATE NOCASE LIMIT '.$limit);
    $q->execute([$albumId]);
    return array_values(array_map(static fn($v): string => (string)$v, $q->fetchAll(PDO::FETCH_COLUMN)));
}
