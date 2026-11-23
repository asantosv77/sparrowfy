<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require_once dirname(__DIR__).'/auth.php';
require_once dirname(__DIR__).'/lib/mobile_sessions.php';
try{$p=lab_auth_pdo();$p->exec('PRAGMA foreign_keys=ON');sf_mobile_sessions_schema($p);$p->beginTransaction();
$p->exec("CREATE TABLE IF NOT EXISTS favorites(user_id TEXT NOT NULL,track_id TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(user_id,track_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE)");
$p->exec("CREATE TABLE IF NOT EXISTS recent_tracks(user_id TEXT NOT NULL,track_id TEXT NOT NULL,played_at TEXT NOT NULL,play_count INTEGER NOT NULL DEFAULT 1,PRIMARY KEY(user_id,track_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE)");
$p->exec("CREATE TABLE IF NOT EXISTS playlists(id TEXT PRIMARY KEY,owner_id TEXT NOT NULL,name TEXT NOT NULL,description TEXT NOT NULL DEFAULT '',is_shared INTEGER NOT NULL DEFAULT 0 CHECK(is_shared IN (0,1)),created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE)");
$p->exec("CREATE TABLE IF NOT EXISTS playlist_tracks(playlist_id TEXT NOT NULL,track_id TEXT NOT NULL,position INTEGER NOT NULL,added_at TEXT NOT NULL,PRIMARY KEY(playlist_id,track_id),FOREIGN KEY(playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE)");
$p->exec("CREATE TABLE IF NOT EXISTS playlist_shares(playlist_id TEXT NOT NULL,shared_with_user_id TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(playlist_id,shared_with_user_id),FOREIGN KEY(playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,FOREIGN KEY(shared_with_user_id) REFERENCES users(id) ON DELETE CASCADE)");
$p->exec("CREATE TABLE IF NOT EXISTS user_suggestions(id TEXT PRIMARY KEY,user_id TEXT NOT NULL,subject TEXT NOT NULL,message TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'new',created_at TEXT NOT NULL,admin_response TEXT,responded_at TEXT,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
$p->exec('CREATE INDEX IF NOT EXISTS idx_tracks_album_position ON tracks(album_id,position)');$p->exec('CREATE INDEX IF NOT EXISTS idx_recent_user_played ON recent_tracks(user_id,played_at DESC)');$p->exec('CREATE INDEX IF NOT EXISTS idx_playlists_owner ON playlists(owner_id,updated_at DESC)');$p->commit();echo "Migración Mobile API aplicada correctamente.\n";
}catch(Throwable $e){if(isset($p)&&$p->inTransaction())$p->rollBack();fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
