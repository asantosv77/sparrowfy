<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../auth.php';
lab_auth_pdo()->exec('CREATE TABLE IF NOT EXISTS user_ui_preferences (
 user_id TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
 show_management_controls INTEGER NOT NULL DEFAULT 1 CHECK(show_management_controls IN (0,1)),
 updated_at TEXT NOT NULL
)');
echo "Management view schema ready.\n";
