<?php
declare(strict_types=1);

/**
 * Safety helpers for destructive actions. This module intentionally keeps
 * album.json as the current read source while requiring SQLite consistency
 * whenever the configured SQLite catalog exists.
 */
function hml_delete_db_path(): string {
    $env = getenv('HLS_MUSIC_LAB_SQLITE');
    return is_string($env) && trim($env) !== '' ? trim($env) : '/sparrowfy/database/library.sqlite';
}

function hml_delete_probe_directory(string $dir): void {
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('No hay permisos de escritura en el directorio: ' . $dir . '. No se eliminó nada.');
    }
    $probe = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.hml-write-probe-' . bin2hex(random_bytes(6));
    $handle = @fopen($probe, 'x');
    if ($handle === false) {
        throw new RuntimeException('No se pudo comprobar escritura en: ' . $dir . '. No se eliminó nada.');
    }
    fclose($handle);
    if (!@unlink($probe)) {
        throw new RuntimeException('Se pudo probar escritura, pero no limpiar el archivo temporal en: ' . $dir . '. No se eliminó nada.');
    }
}

/** Check that every directory needed to remove the tree is writable; reject symlinks. */
function hml_delete_preflight_tree(string $path): void {
    if (!file_exists($path) || is_link($path)) {
        throw new RuntimeException('La ruta que se pretende eliminar no existe o es un enlace simbólico: ' . $path . '. No se eliminó nada.');
    }
    $parent = dirname($path);
    hml_delete_probe_directory($parent);
    if (is_dir($path)) {
        hml_delete_probe_directory($path);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('Se encontró un enlace simbólico dentro de ' . $path . '. No se eliminó nada.');
            }
            if ($item->isDir()) {
                hml_delete_probe_directory($item->getPathname());
            } else {
                // Unlink depende del permiso del directorio padre, no del modo del archivo.
                hml_delete_probe_directory(dirname($item->getPathname()));
            }
        }
    }
}

function hml_delete_atomic_save_album(array $album): void {
    $id = (string)($album['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) throw new RuntimeException('ID de álbum inválido al guardar.');
    $dir = LAB_ALBUMS . '/' . $id;
    $file = $dir . '/album.json';
    if (!is_dir($dir) || !is_file($file)) throw new RuntimeException('No se encontró album.json para escribir.');
    hml_delete_probe_directory($dir);
    $json = json_encode($album, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) throw new RuntimeException('No se pudo serializar album.json.');
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('No se pudo escribir el JSON temporal; no se eliminó nada.');
    }
    @chmod($tmp, 0664);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('No se pudo sustituir album.json de forma atómica; no se eliminó nada.');
    }
}

function hml_delete_sqlite_open_and_validate(string $albumId, array $album): ?PDO {
    $dbPath = hml_delete_db_path();
    if (!is_file($dbPath)) return null; // Compatibilidad con instalaciones aún sin SQLite.
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Existe library.sqlite, pero PDO_SQLite no está disponible. No se eliminó nada.');
    }
    if (!is_readable($dbPath) || !is_writable($dbPath) || !is_writable(dirname($dbPath))) {
        throw new RuntimeException('SQLite existe, pero el usuario PHP no puede leer/escribir la base o su directorio. No se eliminó nada.');
    }
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $required = ['albums', 'tracks', 'track_renditions'];
    foreach ($required as $table) {
        $q = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $q->execute([$table]);
        $exists = (bool)$q->fetchColumn();
        $q->closeCursor();
        if (!$exists) throw new RuntimeException('SQLite no tiene la tabla requerida ' . $table . '. No se eliminó nada.');
    }

    // Real write probe in a rolled-back transaction; catches read-only DBs, locks and sidecar permission issues.
    $pdo->beginTransaction();
    try {
        $probeTable = '__hml_write_probe_' . bin2hex(random_bytes(5));
        $pdo->exec('CREATE TABLE ' . $probeTable . ' (id INTEGER)');
        $pdo->exec('DROP TABLE ' . $probeTable);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new RuntimeException('SQLite no superó la prueba real de escritura (' . $e->getMessage() . '). No se eliminó nada.');
    }

    $q = $pdo->prepare('SELECT id FROM albums WHERE id = ?');
    $q->execute([$albumId]);
    if (!$q->fetchColumn()) {
        $pdo->rollBack();
        throw new RuntimeException('El álbum no existe en SQLite. Ejecuta primero la reconciliación JSON → SQLite. No se eliminó nada.');
    }
    $jsonIds = [];
    foreach (($album['tracks'] ?? []) as $t) {
        if (!is_array($t) || !preg_match('/^[a-f0-9]{16}$/', (string)($t['id'] ?? ''))) {
            $pdo->rollBack();
            throw new RuntimeException('album.json contiene una pista con ID inválido. No se eliminó nada.');
        }
        $jsonIds[] = (string)$t['id'];
    }
    if (count($jsonIds) !== count(array_unique($jsonIds))) {
        $pdo->rollBack();
        throw new RuntimeException('album.json contiene IDs de pista duplicados. No se eliminó nada.');
    }
    $q = $pdo->prepare('SELECT id FROM tracks WHERE album_id = ? ORDER BY id');
    $q->execute([$albumId]);
    $dbIds = array_map('strval', array_column($q->fetchAll(), 'id'));
    $jsonSorted = $jsonIds;
    sort($jsonSorted, SORT_STRING);
    if ($dbIds !== $jsonSorted) {
        $pdo->rollBack();
        $missing = array_values(array_diff($jsonSorted, $dbIds));
        $stale = array_values(array_diff($dbIds, $jsonSorted));
        $detail = [];
        if ($missing) $detail[] = 'faltan en SQLite: ' . implode(', ', $missing);
        if ($stale) $detail[] = 'sobran en SQLite: ' . implode(', ', $stale);
        throw new RuntimeException('JSON y SQLite no coinciden para este álbum (' . implode('; ', $detail) . '). Ejecuta scripts/reconcile_json_sqlite.php --dry-run y revisa el informe. No se eliminó nada.');
    }
    return $pdo; // transaction remains open; caller commits or rolls back.
}

function hml_delete_validate_track_dir(string $albumId, array $track): string {
    $hls = (string)($track['hls'] ?? '');
    $parts = explode('/', $hls);
    if ($hls === '' || count($parts) !== 4 || $parts[0] !== 'hls_out'
        || $parts[1] === '' || !str_starts_with($parts[1], $albumId . '_')
        || $parts[2] === '' || $parts[3] !== 'master.m3u8'
        || in_array('..', $parts, true) || in_array('.', $parts, true)
        || str_contains($hls, '\\') || str_contains($hls, "\0")) {
        throw new RuntimeException('La ruta HLS no tiene el formato esperado. No se eliminó nada.');
    }
    $hlsRoot = realpath(LAB_HLS);
    $master = realpath(dirname(__DIR__) . '/' . $hls);
    if ($hlsRoot === false || $master === false || !is_file($master)
        || basename($master) !== 'master.m3u8'
        || !str_starts_with($master, $hlsRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('No se encontró un manifiesto HLS válido para esta pista. Corrige la inconsistencia antes de eliminar.');
    }
    $jobDir = dirname($master);
    $albumDir = dirname($jobDir);
    if (basename($albumDir) !== $parts[1] || basename($jobDir) !== $parts[2]
        || realpath($albumDir) !== $hlsRoot . DIRECTORY_SEPARATOR . $parts[1]
        || ((string)($track['job_id'] ?? '') !== '' && basename($jobDir) !== (string)$track['job_id'])) {
        throw new RuntimeException('La ruta HLS no coincide con los metadatos de la pista. No se eliminó nada.');
    }
    hml_delete_preflight_tree($jobDir);
    return $jobDir;
}

function hml_delete_quarantine(string $path): string {
    if (!file_exists($path) || is_link($path)) throw new RuntimeException('La ruta a cuarentena ya no existe o es un enlace: ' . $path);
    $target = dirname($path) . DIRECTORY_SEPARATOR . '.hml-trash-' . basename($path) . '-' . bin2hex(random_bytes(5));
    if (!@rename($path, $target)) throw new RuntimeException('No se pudo mover a cuarentena: ' . $path . '. No se eliminó nada.');
    return $target;
}

function hml_delete_tree(string $path): void {
    if (!file_exists($path)) return;
    if (is_link($path)) throw new RuntimeException('Se rechazó eliminar un enlace simbólico: ' . $path);
    if (is_dir($path)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isLink()) throw new RuntimeException('Se rechazó un enlace simbólico durante la limpieza: ' . $item->getPathname());
            $ok = $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            if (!$ok && file_exists($item->getPathname())) throw new RuntimeException('No se pudo limpiar: ' . $item->getPathname());
        }
        if (!@rmdir($path) && is_dir($path)) throw new RuntimeException('No se pudo limpiar: ' . $path);
    } elseif (!@unlink($path) && file_exists($path)) {
        throw new RuntimeException('No se pudo eliminar: ' . $path);
    }
}

function hml_delete_rollback(PDO|null $pdo, array $quarantines, ?array $originalAlbum = null): array {
    $errors = [];
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $e) { $errors[] = 'rollback SQLite: ' . $e->getMessage(); }
    }
    if ($originalAlbum !== null) {
        try { hml_delete_atomic_save_album($originalAlbum); } catch (Throwable $e) { $errors[] = 'restauración JSON: ' . $e->getMessage(); }
    }
    foreach (array_reverse($quarantines) as $q) {
        if (!file_exists($q['quarantine'])) continue;
        if (file_exists($q['original']) || !@rename($q['quarantine'], $q['original'])) {
            $errors[] = 'no se pudo restaurar ' . $q['original'] . '; contenido en cuarentena: ' . $q['quarantine'];
        }
    }
    return $errors;
}
