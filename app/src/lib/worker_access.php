<?php
declare(strict_types=1);

/** Manager-owned worker registration. Legacy status "approved" means registered. */
function sf_worker_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS sf_worker_requests (
        id TEXT PRIMARY KEY, user_id TEXT NOT NULL, device_name TEXT NOT NULL COLLATE NOCASE,
        status TEXT NOT NULL CHECK(status IN ('pending','approved','rejected','revoked')),
        requested_at INTEGER NOT NULL, reviewed_at INTEGER, reviewed_by TEXT,
        UNIQUE(user_id,device_name))");
    $db->exec("CREATE TABLE IF NOT EXISTS sf_worker_access_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, request_id TEXT NOT NULL,
        actor_id TEXT NOT NULL, action TEXT NOT NULL, created_at INTEGER NOT NULL)");
}

function sf_worker_is_manager(array $account): bool {
    return !empty($account['is_active']) && (($account['role'] ?? '') === 'admin' || !empty($account['can_manage_albums']));
}

function sf_worker_request(PDO $db, array $account, string $name): string {
    if (!sf_worker_is_manager($account)) throw new RuntimeException('Solo las cuentas gestoras activas pueden registrar un worker.');
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 80 || preg_match('/[\x00-\x1f\x7f]/u', $name)) {
        throw new RuntimeException('Escribe un nombre de equipo de 1 a 80 caracteres, sin saltos de línea.');
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $q = $db->prepare('SELECT id,status FROM sf_worker_requests WHERE user_id=? AND device_name=?');
        $q->execute([$account['id'], $name]);
        $old = $q->fetch(PDO::FETCH_ASSOC);
        $q->closeCursor();
        if ($old && $old['status'] === 'approved') {
            $db->exec('COMMIT');
            return $old['id'];
        }
        $q = $db->prepare("SELECT count(*) FROM sf_worker_requests WHERE user_id=? AND status IN ('pending','approved')");
        $q->execute([$account['id']]);
        $count = (int)$q->fetchColumn();
        $q->closeCursor();
        if ($count >= 5 && (!$old || $old['status'] !== 'pending')) throw new RuntimeException('Ya tienes cinco equipos registrados. Retira uno antes de registrar otro.');
        $id = $old ? $old['id'] : bin2hex(random_bytes(16));
        $q = $db->prepare("INSERT INTO sf_worker_requests(id,user_id,device_name,status,requested_at)
            VALUES(?,?,?,'approved',?) ON CONFLICT(user_id,device_name) DO UPDATE SET
            status='approved',requested_at=excluded.requested_at,reviewed_at=NULL,reviewed_by=NULL");
        $q->execute([$id, $account['id'], $name, time()]);
        sf_worker_clear_agent($db, $id);
        sf_worker_event($db, $id, (string)$account['id'], 'self_registered');
        $db->exec('COMMIT');
        return $id;
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}

function sf_worker_event(PDO $db, string $id, string $actor, string $action): void {
    $db->prepare('INSERT INTO sf_worker_access_events(request_id,actor_id,action,created_at) VALUES(?,?,?,?)')
        ->execute([$id, $actor, $action, time()]);
}

function sf_worker_review(PDO $db, array $actor, string $id, string $action, bool $enabled): void {
    if (!sf_worker_is_manager($actor)) throw new RuntimeException('Necesitas una cuenta gestora activa.');
    $admin = ($actor['role'] ?? '') === 'admin';
    if (!preg_match('/^[a-f0-9]{32}$/D', $id) || $action !== 'revoke') throw new RuntimeException('Acción de equipo inválida. No se requiere aprobación para registrar workers.');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $q = $db->prepare('SELECT r.*,u.role,u.is_active,u.can_manage_albums FROM sf_worker_requests r JOIN users u ON u.id=r.user_id WHERE r.id=?');
        $q->execute([$id]); $row = $q->fetch(PDO::FETCH_ASSOC); $q->closeCursor();
        if (!$row || (!$admin && $row['user_id'] !== $actor['id'])) throw new RuntimeException('Solicitud no disponible para tu cuenta.');
        if ($action === 'revoke' && !in_array($row['status'], ['pending','approved'], true)) throw new RuntimeException('La solicitud ya está retirada.');
        $status = 'revoked';
        $db->prepare('UPDATE sf_worker_requests SET status=?,reviewed_at=?,reviewed_by=? WHERE id=?')
            ->execute([$status, time(), $actor['id'], $id]);
        sf_worker_clear_agent($db, $id);
        sf_worker_event($db, $id, (string)$actor['id'], $status);
        $db->exec('COMMIT');
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}

function sf_worker_list(PDO $db, ?string $userId = null): array {
    $sql = 'SELECT r.*,u.username,u.role,u.is_active,u.can_manage_albums FROM sf_worker_requests r JOIN users u ON u.id=r.user_id';
    if ($userId !== null) $sql .= ' WHERE r.user_id=?';
    $q = $db->prepare($sql . " ORDER BY CASE r.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,r.requested_at DESC LIMIT 200");
    $q->execute($userId === null ? [] : [$userId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function sf_worker_status_label(array $row): string {
    if (!sf_worker_is_manager($row)) return 'Sin permiso: cuenta inactiva o sin gestión';
    return ['pending'=>'Pendiente de registro','approved'=>'Registrado · pendiente de vinculación',
        'rejected'=>'Retirado','revoked'=>'Retirado'][$row['status']] ?? 'Desconocido';
}

function sf_worker_clear_agent(PDO $db, string $id): void {
    if (!$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sf_desktop_agents'")->fetchColumn()) return;
    $db->prepare('UPDATE sf_desktop_agents SET token_hash=NULL,credential_stamp=NULL,pair_hash=NULL,pair_expires=NULL,last_seen=NULL WHERE request_id=?')->execute([$id]);
    $db->prepare("UPDATE sf_desktop_tasks SET state='failed',error='Equipo revocado o retirado',updated_at=? WHERE request_id=? AND state IN ('queued','leased','uploading','validating')")->execute([time(),$id]);
}

/** Called in the same transaction as an account permission/state change. */
function sf_worker_sync_account(PDO $db, string $userId, string $actorId): int {
    $q=$db->prepare('SELECT role,is_active,can_manage_albums FROM users WHERE id=?');
    $q->execute([$userId]);$account=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if($account&&sf_worker_is_manager($account))return 0;
    $q=$db->prepare('SELECT id,status FROM sf_worker_requests WHERE user_id=?');
    $q->execute([$userId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$revoked=0;
    foreach($rows as $row){
        sf_worker_clear_agent($db,$row['id']);
        if(!in_array($row['status'],['pending','approved'],true))continue;
        $db->prepare("UPDATE sf_worker_requests SET status='revoked',reviewed_at=?,reviewed_by=? WHERE id=?")->execute([time(),$actorId,$row['id']]);
        sf_worker_event($db,$row['id'],$actorId,'management_access_removed');$revoked++;
    }
    return $revoked;
}
