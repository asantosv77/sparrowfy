<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib.php';
function sf_jobs_db(): PDO {
    $db = lab_auth_pdo();
    $db->exec('CREATE TABLE IF NOT EXISTS audio_edit_jobs (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, album_id TEXT NOT NULL, track_id TEXT NOT NULL, track_name TEXT NOT NULL, parameters TEXT NOT NULL, state TEXT NOT NULL, stage TEXT NOT NULL, progress INTEGER, source_kind TEXT, source_quality TEXT, error TEXT, created_at TEXT NOT NULL, started_at TEXT, finished_at TEXT, notified_at TEXT, updated_at TEXT NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_audio_edit_jobs_user ON audio_edit_jobs(user_id,created_at)');
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_audio_edit_jobs_active_track ON audio_edit_jobs(track_id) WHERE state IN ("queued","running")');
    return $db;
}
function sf_jobs_update(PDO $db,string $id,string $stage,?int $progress=null,?string $kind=null,?string $quality=null): void {
    $q=$db->prepare('UPDATE audio_edit_jobs SET stage=?,progress=?,source_kind=COALESCE(?,source_kind),source_quality=COALESCE(?,source_quality),updated_at=? WHERE id=?');
    $q->execute([$stage,$progress,$kind,$quality,gmdate('c'),$id]);
}
function sf_jobs_public(array $r): array {
    foreach (['parameters'] as $k) unset($r[$k]);
    return $r;
}

/**
 * Comprueba que una pista tenga una fuente utilizable para recorte/fade.
 * Solo lectura: no reconstruye, convierte ni modifica el catálogo.
 * Lanza RuntimeException con un motivo específico si no es editable.
 */
function sf_audio_edit_source_check(array $track): array {
    $jobId = (string) ($track['job_id'] ?? '');
    if ($jobId === '') {
        throw new RuntimeException('Esta pista no tiene conversión HLS. Conviértela antes de editar el audio.');
    }
    $dir = lab_find_job_dir($jobId);
    if ($dir === null) {
        throw new RuntimeException('No se encuentra la conversión HLS de esta pista. Comprueba su ruta o vuelve a convertirla.');
    }
    $infoFile = $dir . '/info.json';
    $info = json_decode((string) @file_get_contents($infoFile), true);
    if (!is_array($info)) {
        throw new RuntimeException('Los metadatos de la conversión HLS están dañados.');
    }
    if (!is_file($dir . '/master.m3u8') || !is_readable($dir . '/master.m3u8')) {
        throw new RuntimeException('Falta la lista principal master.m3u8 de esta pista.');
    }

    // Si se conserva una fuente válida, el worker puede editarla directamente.
    $source = (string) ($info['source_file'] ?? '');
    if ($source !== '' && basename($source) === $source &&
        !in_array($source, ['.', '..'], true) &&
        is_file($dir . '/' . $source) && is_readable($dir . '/' . $source)) {
        return ['dir' => $dir, 'info' => $info, 'kind' => 'original'];
    }

    // Si no hay fuente, la reconstrucción requiere una variante HLS completa.
    $highest = (string) ($info['highest'] ?? '');
    if ($highest === '' || !preg_match('/\A[a-zA-Z0-9_.-]+\z/D', $highest) ||
        $highest === '.' || $highest === '..') {
        throw new RuntimeException('No hay una variante HLS válida para reconstruir el audio.');
    }
    $variantDir = $dir . '/' . $highest;
    $playlist = $variantDir . '/prog.m3u8';
    if (!is_file($playlist) || !is_readable($playlist)) {
        throw new RuntimeException('Falta la lista HLS de mayor calidad (' . $highest . ').');
    }
    $content = @file_get_contents($playlist);
    if ($content === false || !str_contains($content, '#EXT-X-ENDLIST')) {
        throw new RuntimeException('La lista HLS de mayor calidad está incompleta.');
    }
    $segments = 0;
    foreach (preg_split('/\r?\n/', $content) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        // El convertidor propio genera segmentos locales, no URLs remotas.
        if (basename($line) !== $line || in_array($line, ['.', '..'], true) ||
            !is_file($variantDir . '/' . $line) || !is_readable($variantDir . '/' . $line)) {
            throw new RuntimeException('Faltan segmentos de audio en la variante ' . $highest . '.');
        }
        $segments++;
    }
    if ($segments === 0) {
        throw new RuntimeException('La variante HLS no contiene segmentos de audio.');
    }
    return ['dir' => $dir, 'info' => $info, 'kind' => 'hls_reconstructed'];
}
