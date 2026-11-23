<?php
declare(strict_types=1);
/** Bearer sessions shared by the mobile API and HLS delivery. Idempotent schema. */
function sf_mobile_sessions_schema(PDO $p): void {
    static $ready = false;
    if ($ready) return;
    $p->exec("CREATE TABLE IF NOT EXISTS sessions(id TEXT PRIMARY KEY,user_id TEXT NOT NULL,refresh_token_hash TEXT NOT NULL,created_at TEXT NOT NULL,expires_at TEXT NOT NULL,device_label TEXT NOT NULL DEFAULT '',last_used_at TEXT,revoked_at TEXT,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
    $p->exec('CREATE INDEX IF NOT EXISTS sf_sessions_token ON sessions(refresh_token_hash)');
    $p->exec('CREATE INDEX IF NOT EXISTS sf_sessions_user ON sessions(user_id,expires_at)');
    $p->exec('CREATE INDEX IF NOT EXISTS sf_sessions_expiry ON sessions(expires_at)');
    // Keep the table bounded without deleting recently revoked tokens needed for auditing.
    // HLS segment traffic hits this path constantly, so prune only occasionally.
    if (random_int(1, 50) === 1) {
        $p->prepare('DELETE FROM sessions WHERE expires_at < ?')->execute([gmdate('c', time() - 86400)]);
    }
    $ready = true;
}
function sf_mobile_sessions_revoke_user(PDO $p, string $userId): int {
    if ($userId === '') return 0;
    sf_mobile_sessions_schema($p);
    $q = $p->prepare('UPDATE sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL');
    $q->execute([gmdate('c'), $userId]);
    return $q->rowCount();
}
