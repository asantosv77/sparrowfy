<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/auth.php';
lab_auth_pdo()->exec("CREATE TABLE IF NOT EXISTS sf_client_memory_preferences (user_id TEXT PRIMARY KEY, memory_mib INTEGER NOT NULL DEFAULT 0 CHECK(memory_mib IN (0,128,192,256,384,512,640)), updated_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
echo "Client memory preferences ready\n";
