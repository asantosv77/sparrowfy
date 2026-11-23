<?php
declare(strict_types=1);

/** Preferencias personales persistidas por cuenta (compartidas entre dispositivos). */
const LAB_HLS_ALLOWED_PROFILES = ['continuity', 'balanced_mobile', 'audio_quality'];
const LAB_HLS_DEFAULT_PROFILE = 'balanced_mobile';

function lab_user_hls_profile(string $userId): string {
    if ($userId === '') return LAB_HLS_DEFAULT_PROFILE;
    try {
        $pdo = lab_auth_pdo();
        $q = $pdo->prepare('SELECT hls_profile FROM user_preferences WHERE user_id = ? LIMIT 1');
        $q->execute([$userId]);
        $value = $q->fetchColumn();
        $q->closeCursor();
        return is_string($value) && in_array($value, LAB_HLS_ALLOWED_PROFILES, true)
            ? $value : LAB_HLS_DEFAULT_PROFILE;
    } catch (Throwable $e) {
        // Si la migración aún no se ejecutó, mantener la reproducción disponible con el valor seguro.
        error_log('HLS Music Lab user preferences read: ' . get_class($e) . ': ' . $e->getMessage());
        return LAB_HLS_DEFAULT_PROFILE;
    }
}

function lab_user_save_hls_profile(string $userId, string $profile): void {
    if ($userId === '') throw new RuntimeException('No se pudo identificar la cuenta autenticada.');
    if (!in_array($profile, LAB_HLS_ALLOWED_PROFILES, true)) throw new RuntimeException('Selecciona un perfil HLS válido.');
    $pdo = lab_auth_pdo();
    $q = $pdo->prepare(
        'INSERT INTO user_preferences(user_id, hls_profile, updated_at) VALUES(?, ?, ?) '
        . 'ON CONFLICT(user_id) DO UPDATE SET hls_profile=excluded.hls_profile, updated_at=excluded.updated_at'
    );
    $q->execute([$userId, $profile, gmdate('c')]);
}

function lab_user_show_tips(string $userId): bool {
    $q = lab_auth_pdo()->prepare('SELECT show_tips FROM user_tip_preferences WHERE user_id = ?');
    $q->execute([$userId]);
    $value = $q->fetchColumn();
    $q->closeCursor();
    return $value === false || (int)$value === 1;
}
function lab_user_save_tips(string $userId, bool $enabled): void {
    if ($userId === '') throw new RuntimeException('No se pudo identificar tu cuenta.');
    $q = lab_auth_pdo()->prepare('INSERT INTO user_tip_preferences(user_id,show_tips,updated_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET show_tips=excluded.show_tips,updated_at=excluded.updated_at');
    $q->execute([$userId, $enabled ? 1 : 0, gmdate('c')]);
}

/** Per-request cache avoids a SQLite query for every track's context menu. */
function &lab_user_management_controls_cache(): array {
    static $cache = [];
    return $cache;
}
/** Missing preference means enabled, preserving the existing manager interface. */
function lab_user_show_management_controls(string $userId): bool {
    if ($userId === '') return true;
    $cache =& lab_user_management_controls_cache();
    if (array_key_exists($userId, $cache)) return $cache[$userId];
    $q = lab_auth_pdo()->prepare('SELECT show_management_controls FROM user_ui_preferences WHERE user_id = ?');
    $q->execute([$userId]);
    $value = $q->fetchColumn();
    $q->closeCursor();
    return $cache[$userId] = ($value === false || (int)$value === 1);
}
function lab_user_save_management_controls(string $userId, bool $enabled): void {
    $account = lab_auth_current_account();
    if (!lab_auth_can_manage_albums() || !is_array($account) || (string)$account['id'] !== $userId) {
        throw new RuntimeException('No puedes modificar esta preferencia.');
    }
    $q = lab_auth_pdo()->prepare('INSERT INTO user_ui_preferences(user_id,show_management_controls,updated_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET show_management_controls=excluded.show_management_controls,updated_at=excluded.updated_at');
    $q->execute([$userId, $enabled ? 1 : 0, gmdate('c')]);
    $cache =& lab_user_management_controls_cache();
    $cache[$userId] = $enabled;
}

/** Presupuesto local de WebCodecs por cuenta. 0 = automatico. */
const LAB_CLIENT_MEMORY_TIERS = [0, 128, 192, 256, 384, 512, 640];
function lab_user_client_memory(string $userId): int {
    if ($userId === '') return 0;
    try {
        $q = lab_auth_pdo()->prepare('SELECT memory_mib FROM sf_client_memory_preferences WHERE user_id = ? LIMIT 1');
        $q->execute([$userId]);
        $value = $q->fetchColumn();
        $q->closeCursor();
        return in_array((int)$value, LAB_CLIENT_MEMORY_TIERS, true) ? (int)$value : 0;
    } catch (Throwable $e) {
        // Sin la tabla (migracion pendiente) se conserva el valor automatico.
        return 0;
    }
}
function lab_user_save_client_memory(string $userId, int $mib): void {
    $account = lab_auth_current_account();
    if (!lab_auth_can_manage_albums() || !is_array($account) || (string)$account['id'] !== $userId) {
        throw new RuntimeException('Solo las cuentas gestoras pueden cambiar esta preferencia.');
    }
    if (!in_array($mib, LAB_CLIENT_MEMORY_TIERS, true)) throw new RuntimeException('Selecciona un límite de memoria válido.');
    $pdo = lab_auth_pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS sf_client_memory_preferences (user_id TEXT PRIMARY KEY, memory_mib INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $q = $pdo->prepare('INSERT INTO sf_client_memory_preferences(user_id,memory_mib,updated_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET memory_mib=excluded.memory_mib,updated_at=excluded.updated_at');
    $q->execute([$userId, $mib, gmdate('c')]);
}

/** Tema visual persistido por cuenta. Se resuelve antes de pintar para evitar el destello del tema por defecto. */
const LAB_THEME_CHOICES = ['dark-teal', 'dark-blue', 'dark-purple', 'light', 'light-sand', 'solarized', 'monokai', 'deep-black', 'ruby-blue'];
const LAB_THEME_DEFAULT = 'dark-teal';
const LAB_THEME_COLORS = [
    'dark-teal' => '#091519',
    'dark-blue' => '#080f1d',
    'dark-purple' => '#100b19',
    'light' => '#eef2f6',
    'light-sand' => '#f4f0e7',
    'solarized' => '#002b36',
    'monokai' => '#272822',
    'deep-black' => '#000000',
    'ruby-blue' => '#0b1f33',
];
function lab_user_theme(string $userId): string {
    if ($userId === '') return LAB_THEME_DEFAULT;
    try {
        $q = lab_auth_pdo()->prepare('SELECT theme FROM user_theme_preferences WHERE user_id = ? LIMIT 1');
        $q->execute([$userId]);
        $theme = $q->fetchColumn();
        $q->closeCursor();
        return is_string($theme) && in_array($theme, LAB_THEME_CHOICES, true) ? $theme : LAB_THEME_DEFAULT;
    } catch (Throwable $e) {
        // La tabla se crea al guardar el primer tema; hasta entonces se usa el valor seguro.
        return LAB_THEME_DEFAULT;
    }
}
function lab_theme_meta_color(string $theme): string {
    return LAB_THEME_COLORS[$theme] ?? LAB_THEME_COLORS[LAB_THEME_DEFAULT];
}
