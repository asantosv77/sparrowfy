<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/auth.php';
lab_auth_pdo()->exec("CREATE TABLE IF NOT EXISTS user_tip_preferences (user_id TEXT PRIMARY KEY, show_tips INTEGER NOT NULL DEFAULT 1 CHECK(show_tips IN (0,1)), updated_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
echo "User tips preferences ready\n";
