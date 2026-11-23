<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__) . '/auth.php';
try {
    $db = lab_auth_pdo();
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 15000');
    $db->beginTransaction();
    $db->exec("CREATE TABLE IF NOT EXISTS user_preferences (
        user_id TEXT PRIMARY KEY,
        hls_profile TEXT NOT NULL DEFAULT 'balanced_mobile'
          CHECK (hls_profile IN ('continuity', 'balanced_mobile', 'audio_quality')),
        updated_at TEXT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $insert = $db->prepare("INSERT OR IGNORE INTO user_preferences(user_id, hls_profile, updated_at) VALUES(?, 'balanced_mobile', ?)");
    $users = $db->query("SELECT id FROM users WHERE id IS NOT NULL AND id <> ''");
    $ids = $users->fetchAll(PDO::FETCH_COLUMN);
    $users->closeCursor();
    foreach ($ids as $id) $insert->execute([(string)$id, gmdate('c')]);
    $db->commit();
    echo 'Migración de preferencias HLS aplicada. Cuentas inicializadas: ' . count($ids) . PHP_EOL;
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
