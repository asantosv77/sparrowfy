<?php
declare(strict_types=1);
require_once __DIR__.'/user_preferences.php';
/** Presentation only. Authorization must use lab_auth_can_manage_albums(). */
function lab_ui_can_manage_albums(): bool {
    if (!lab_auth_can_manage_albums()) return false;
    $account = lab_auth_current_account();
    return lab_user_show_management_controls((string)($account['id'] ?? ''));
}
