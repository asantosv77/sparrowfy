<?php
declare(strict_types=1);
/**
 * Solicitudes de eliminación de colecciones.
 *
 * Un gestor no puede borrar una colección directamente: registra una solicitud.
 * Mientras está pendiente, la colección se oculta en su biblioteca y el
 * administrador decide en el panel «Confirmar Eliminación de Colección» si la
 * borra definitivamente o la restaura.
 */
require_once dirname(__DIR__).'/lib.php';

function sf_delreq_db(): PDO {
    static $db = null;
    if ($db instanceof PDO) return $db;
    $db = lab_auth_pdo();
    $db->exec('CREATE TABLE IF NOT EXISTS sf_album_deletion_requests (
        album_id TEXT PRIMARY KEY,
        requested_by TEXT NOT NULL,
        requested_name TEXT NOT NULL,
        requested_at TEXT NOT NULL,
        state TEXT NOT NULL,
        resolved_by TEXT,
        resolved_name TEXT,
        resolved_at TEXT
    )');
    return $db;
}

/** @return array<int,array<string,string>> */
function sf_delreq_pending(): array {
    try {
        $q = sf_delreq_db()->query("SELECT * FROM sf_album_deletion_requests WHERE state='pending' ORDER BY requested_at DESC");
        return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('SparrowFy deletion requests: '.$e->getMessage());
        return [];
    }
}

/** @return array<int,string> */
function sf_delreq_pending_ids(): array {
    return array_map(static fn(array $r): string => (string)$r['album_id'], sf_delreq_pending());
}

function sf_delreq_for_album(string $albumId): ?array {
    $q = sf_delreq_db()->prepare('SELECT * FROM sf_album_deletion_requests WHERE album_id=? LIMIT 1');
    $q->execute([$albumId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $q->closeCursor();
    return $row ?: null;
}

function sf_delreq_request(string $albumId, string $userId, string $userName): void {
    $now = gmdate('c');
    $q = sf_delreq_db()->prepare("INSERT INTO sf_album_deletion_requests(album_id,requested_by,requested_name,requested_at,state)
        VALUES(?,?,?,?,'pending')
        ON CONFLICT(album_id) DO UPDATE SET
            requested_by=excluded.requested_by,
            requested_name=excluded.requested_name,
            requested_at=excluded.requested_at,
            state='pending',
            resolved_by=NULL,
            resolved_name=NULL,
            resolved_at=NULL");
    $q->execute([$albumId, $userId, substr($userName, 0, 200), $now]);
}

function sf_delreq_cancel(string $albumId, string $userId): void {
    $q = sf_delreq_db()->prepare("DELETE FROM sf_album_deletion_requests WHERE album_id=? AND state='pending' AND requested_by=?");
    $q->execute([$albumId, $userId]);
}

function sf_delreq_resolve(string $albumId, string $state, string $adminId, string $adminName): void {
    if (!in_array($state, ['restored', 'deleted'], true)) throw new RuntimeException('Estado no válido');
    $q = sf_delreq_db()->prepare('UPDATE sf_album_deletion_requests SET state=?, resolved_by=?, resolved_name=?, resolved_at=? WHERE album_id=?');
    $q->execute([$state, $adminId, substr($adminName, 0, 200), gmdate('c'), $albumId]);
}

/** ¿La colección debe ocultarse al usuario actual? El administrador ve todo. */
function sf_delreq_is_hidden_for_viewer(string $albumId): bool {
    static $ids = null;
    if ($ids === null) {
        try { $ids = array_flip(sf_delreq_pending_ids()); }
        catch (Throwable $e) { $ids = []; }
    }
    return isset($ids[$albumId]) && !lab_auth_is_admin();
}

/**
 * Filtra colecciones ocultas para el usuario actual.
 * @param array<int,array> $albums
 * @return array<int,array>
 */
function sf_delreq_filter_visible(array $albums): array {
    if (lab_auth_is_admin()) return $albums;
    return array_values(array_filter($albums, static fn($a) => !sf_delreq_is_hidden_for_viewer((string)($a['id'] ?? ''))));
}

/** IDs pendientes como lista SQL segura (ya validados como hex de 16). */
function sf_delreq_sql_hidden_ids(): array {
    return array_values(array_filter(sf_delreq_pending_ids(), static fn($id) => (bool)preg_match('/^[a-f0-9]{16}$/D', $id)));
}
