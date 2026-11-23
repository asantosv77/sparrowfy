CREATE TABLE schema_migrations (
    version INTEGER PRIMARY KEY,
    applied_at TEXT NOT NULL
);
CREATE TABLE albums (
    id TEXT PRIMARY KEY CHECK(length(id) = 16),
    title TEXT NOT NULL,
    artist TEXT NOT NULL DEFAULT '',
    cover TEXT,
    cover_layout_json TEXT,
    created_at TEXT,
    matched_album TEXT,
    matched_artist TEXT,
    metadata_json TEXT,
    imported_at TEXT NOT NULL,
    updated_at TEXT
);
CREATE TABLE tracks (
    id TEXT PRIMARY KEY,
    album_id TEXT NOT NULL REFERENCES albums(id) ON DELETE RESTRICT,
    name TEXT NOT NULL,
    original_name TEXT,
    job_id TEXT,
    hls_path TEXT NOT NULL,
    duration REAL NOT NULL DEFAULT 0 CHECK(duration >= 0),
    source_kbps INTEGER CHECK(source_kbps IS NULL OR source_kbps >= 0),
    position INTEGER NOT NULL DEFAULT 0 CHECK(position >= 0),
    metadata_json TEXT,
    imported_at TEXT NOT NULL,
    updated_at TEXT
);
CREATE TABLE track_renditions (
    track_id TEXT NOT NULL REFERENCES tracks(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    bitrate_kbps INTEGER CHECK(bitrate_kbps IS NULL OR bitrate_kbps >= 0),
    PRIMARY KEY (track_id, name)
);
CREATE TABLE users (
    id TEXT PRIMARY KEY,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('user', 'admin')),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0, 1)),
    created_at TEXT NOT NULL,
    last_login_at TEXT
, updated_at TEXT, can_manage_albums INTEGER NOT NULL
             DEFAULT 0 CHECK(can_manage_albums IN (0,1)));
CREATE TABLE sessions (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    refresh_token_hash TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    revoked_at TEXT,
    device_label TEXT,
    last_used_at TEXT
);
CREATE TABLE lyrics_cache (
    cache_key TEXT PRIMARY KEY,
    artist TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL,
    album TEXT NOT NULL DEFAULT '',
    synced_json TEXT,
    plain_text TEXT,
    source TEXT,
    fetched_at TEXT NOT NULL,
    expires_at TEXT NOT NULL
);
CREATE TABLE auth_sessions (session_id TEXT PRIMARY KEY, session_data TEXT NOT NULL, expires_at INTEGER NOT NULL);
CREATE TABLE auth_login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, attempt_key TEXT NOT NULL, attempted_at INTEGER NOT NULL, success INTEGER NOT NULL CHECK(success IN (0,1)));
CREATE TABLE favorites(user_id TEXT NOT NULL,track_id TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(user_id,track_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE);
CREATE TABLE recent_tracks(user_id TEXT NOT NULL,track_id TEXT NOT NULL,played_at TEXT NOT NULL,play_count INTEGER NOT NULL DEFAULT 1,PRIMARY KEY(user_id,track_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE);
CREATE TABLE playlists(id TEXT PRIMARY KEY,owner_id TEXT NOT NULL,name TEXT NOT NULL,description TEXT NOT NULL DEFAULT '',is_shared INTEGER NOT NULL DEFAULT 0 CHECK(is_shared IN (0,1)),created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE playlist_tracks(playlist_id TEXT NOT NULL,track_id TEXT NOT NULL,position INTEGER NOT NULL,added_at TEXT NOT NULL,PRIMARY KEY(playlist_id,track_id),FOREIGN KEY(playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,FOREIGN KEY(track_id) REFERENCES tracks(id) ON DELETE CASCADE);
CREATE TABLE playlist_shares(playlist_id TEXT NOT NULL,shared_with_user_id TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(playlist_id,shared_with_user_id),FOREIGN KEY(playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,FOREIGN KEY(shared_with_user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE user_suggestions(id TEXT PRIMARY KEY,user_id TEXT NOT NULL,subject TEXT NOT NULL,message TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'new',created_at TEXT NOT NULL,admin_response TEXT,responded_at TEXT,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE user_preferences (
        user_id TEXT PRIMARY KEY,
        hls_profile TEXT NOT NULL DEFAULT 'balanced_mobile'
          CHECK (hls_profile IN ('continuity', 'balanced_mobile', 'audio_quality')),
        updated_at TEXT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );
CREATE TABLE user_theme_preferences (user_id TEXT PRIMARY KEY, theme TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE audio_edit_jobs (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, album_id TEXT NOT NULL, track_id TEXT NOT NULL, track_name TEXT NOT NULL, parameters TEXT NOT NULL, state TEXT NOT NULL, stage TEXT NOT NULL, progress INTEGER, source_kind TEXT, source_quality TEXT, error TEXT, created_at TEXT NOT NULL, started_at TEXT, finished_at TEXT, notified_at TEXT, updated_at TEXT NOT NULL);
CREATE TABLE sf_processing_jobs (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, category TEXT NOT NULL, operation TEXT NOT NULL, album_id TEXT NOT NULL, label TEXT NOT NULL, payload TEXT NOT NULL, state TEXT NOT NULL, stage TEXT NOT NULL, progress INTEGER, error TEXT, result_json TEXT, created_at TEXT NOT NULL, started_at TEXT, finished_at TEXT, updated_at TEXT NOT NULL);
CREATE TABLE sf_playlist_links(playlist_id TEXT PRIMARY KEY,token_hash TEXT NOT NULL UNIQUE,created_at TEXT NOT NULL,FOREIGN KEY(playlist_id) REFERENCES playlists(id) ON DELETE CASCADE);
CREATE TABLE sf_playlist_imports(user_id TEXT NOT NULL,source_id TEXT NOT NULL,copy_id TEXT NOT NULL,PRIMARY KEY(user_id,source_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(copy_id) REFERENCES playlists(id) ON DELETE CASCADE);
CREATE TABLE sf_album_track_refs(album_id TEXT NOT NULL,source_album_id TEXT NOT NULL,track_id TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(album_id,track_id));
CREATE TABLE sf_remember_tokens (selector TEXT PRIMARY KEY,user_id TEXT NOT NULL,validator_hash TEXT NOT NULL,previous_hash TEXT,previous_until INTEGER NOT NULL DEFAULT 0,credential_stamp TEXT NOT NULL,created_at INTEGER NOT NULL,expires_at INTEGER NOT NULL, csrf_seed TEXT);
CREATE TABLE sf_track_loudness(track_id TEXT PRIMARY KEY,lufs REAL,true_peak REAL,lra REAL,status TEXT NOT NULL,updated_at INTEGER NOT NULL, source_fingerprint TEXT);
CREATE TABLE sf_volume_preferences(user_id TEXT PRIMARY KEY,enabled INTEGER NOT NULL);
CREATE TABLE sf_device_epochs(user_id TEXT PRIMARY KEY,epoch INTEGER NOT NULL DEFAULT 0);
CREATE TABLE sf_devices(id TEXT PRIMARY KEY,user_id TEXT NOT NULL,epoch INTEGER NOT NULL,credential_stamp TEXT NOT NULL,created_at INTEGER NOT NULL,last_seen INTEGER NOT NULL,first_ip TEXT NOT NULL,last_ip TEXT NOT NULL,user_agent TEXT NOT NULL,revoked_at INTEGER);
CREATE TABLE sf_device_remember(selector TEXT PRIMARY KEY,device_id TEXT NOT NULL);
CREATE TABLE sf_usage_daily(user_id TEXT NOT NULL,day TEXT NOT NULL,downloaded INTEGER NOT NULL DEFAULT 0,uploaded INTEGER NOT NULL DEFAULT 0,audio INTEGER NOT NULL DEFAULT 0,requests INTEGER NOT NULL DEFAULT 0,errors INTEGER NOT NULL DEFAULT 0,peak_out INTEGER NOT NULL DEFAULT 0,peak_in INTEGER NOT NULL DEFAULT 0,peak_at INTEGER,PRIMARY KEY(user_id,day));
CREATE TABLE sf_usage_minute(user_id TEXT NOT NULL,minute INTEGER NOT NULL,downloaded INTEGER NOT NULL DEFAULT 0,uploaded INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,minute));
CREATE TABLE sf_usage_global(minute INTEGER PRIMARY KEY,downloaded INTEGER NOT NULL DEFAULT 0,uploaded INTEGER NOT NULL DEFAULT 0);
CREATE TABLE sf_usage_batches(id TEXT PRIMARY KEY,processed_at INTEGER NOT NULL);
CREATE TABLE sf_usage_meta(key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE sf_usage_presence(user_id TEXT NOT NULL,device_id TEXT NOT NULL,last_seen INTEGER NOT NULL,playing_until INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,device_id));
CREATE TABLE user_tip_preferences (user_id TEXT PRIMARY KEY, show_tips INTEGER NOT NULL DEFAULT 1 CHECK(show_tips IN (0,1)), updated_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE user_ui_preferences (
 user_id TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
 show_management_controls INTEGER NOT NULL DEFAULT 1 CHECK(show_management_controls IN (0,1)),
 updated_at TEXT NOT NULL
);
CREATE TABLE sf_client_memory_preferences (user_id TEXT PRIMARY KEY, memory_mib INTEGER NOT NULL DEFAULT 0 CHECK(memory_mib IN (0,128,192,256,384,512,640)), updated_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE sf_album_deletion_requests (
        album_id TEXT PRIMARY KEY,
        requested_by TEXT NOT NULL,
        requested_name TEXT NOT NULL,
        requested_at TEXT NOT NULL,
        state TEXT NOT NULL,
        resolved_by TEXT,
        resolved_name TEXT,
        resolved_at TEXT
    );
CREATE TABLE _sf_probe(x INTEGER);
CREATE TABLE sf_loudness_progress_batches(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id TEXT NOT NULL,job_ids TEXT NOT NULL,created_at TEXT NOT NULL);
CREATE TABLE sf_audio_download_permissions(user_id TEXT PRIMARY KEY,enabled INTEGER NOT NULL DEFAULT 0);
CREATE TABLE sf_mornye_requests (request_key TEXT PRIMARY KEY, album_id TEXT NOT NULL, source_url TEXT NOT NULL, target TEXT NOT NULL, job_id TEXT NOT NULL);
CREATE TABLE sf_mornye_batches (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, album_id TEXT NOT NULL, local_mode INTEGER NOT NULL, tracks TEXT NOT NULL, created_at INTEGER NOT NULL);
CREATE TABLE sf_worker_requests (
        id TEXT PRIMARY KEY, user_id TEXT NOT NULL, device_name TEXT NOT NULL COLLATE NOCASE,
        status TEXT NOT NULL CHECK(status IN ('pending','approved','rejected','revoked')),
        requested_at INTEGER NOT NULL, reviewed_at INTEGER, reviewed_by TEXT,
        UNIQUE(user_id,device_name));
CREATE TABLE sf_worker_access_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, request_id TEXT NOT NULL,
        actor_id TEXT NOT NULL, action TEXT NOT NULL, created_at INTEGER NOT NULL);
CREATE TABLE sf_desktop_agents(request_id TEXT PRIMARY KEY,
        token_hash TEXT,credential_stamp TEXT,pair_hash TEXT,pair_expires INTEGER,last_seen INTEGER,
        version TEXT,capabilities TEXT);
CREATE TABLE sf_desktop_tasks(id TEXT PRIMARY KEY,request_id TEXT NOT NULL,
        kind TEXT NOT NULL,parent_id TEXT,source TEXT NOT NULL,source_hash TEXT NOT NULL,source_bytes INTEGER NOT NULL,
        rates TEXT NOT NULL,duration REAL NOT NULL,sample_rate INTEGER NOT NULL,segment_seconds INTEGER NOT NULL,
        state TEXT NOT NULL,lease_hash TEXT,lease_until INTEGER,created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,manifest TEXT,error TEXT);
CREATE TABLE sf_worker_log_options(id INTEGER PRIMARY KEY,enabled INTEGER NOT NULL);
CREATE TABLE sf_worker_task_events(id INTEGER PRIMARY KEY AUTOINCREMENT,task_id TEXT NOT NULL,state TEXT NOT NULL,created_at INTEGER NOT NULL);
CREATE TABLE sf_music_charts(chart_key TEXT PRIMARY KEY,provider TEXT NOT NULL,market TEXT NOT NULL,title TEXT NOT NULL,album_id TEXT,source_url TEXT,source_updated TEXT,last_fetch TEXT,last_week TEXT,error TEXT,enabled INTEGER NOT NULL DEFAULT 1);
CREATE TABLE sf_chart_snapshots(chart_key TEXT NOT NULL,day TEXT NOT NULL,captured_at TEXT NOT NULL,data TEXT NOT NULL,PRIMARY KEY(chart_key,day));
CREATE TABLE sf_chart_entries(chart_key TEXT NOT NULL,rank INTEGER NOT NULL,identity TEXT NOT NULL,data TEXT NOT NULL,PRIMARY KEY(chart_key,rank));
CREATE TABLE sf_chart_audio(identity TEXT PRIMARY KEY,data TEXT NOT NULL,state TEXT NOT NULL DEFAULT "new",track_id TEXT,source_album TEXT,job_id TEXT,service TEXT,url TEXT,attempts TEXT NOT NULL DEFAULT "[]",error TEXT,updated_at TEXT NOT NULL, mornye_job_id TEXT);
CREATE TABLE sf_chart_reports(week TEXT PRIMARY KEY,created_at TEXT NOT NULL,data TEXT NOT NULL);
CREATE TABLE sf_com_notices(id TEXT PRIMARY KEY,author TEXT NOT NULL,title TEXT NOT NULL,body TEXT NOT NULL,created_at INTEGER NOT NULL,cancelled_at INTEGER,request_key TEXT NOT NULL,UNIQUE(author,request_key));
CREATE TABLE sf_com_recipients(notice_id TEXT NOT NULL,user_id TEXT NOT NULL,confirmed_at INTEGER,PRIMARY KEY(notice_id,user_id));
CREATE TABLE sf_com_threads(id TEXT PRIMARY KEY,user_id TEXT NOT NULL,subject TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'new',created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL,request_key TEXT NOT NULL,UNIQUE(user_id,request_key));
CREATE TABLE sf_com_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,thread_id TEXT NOT NULL,author TEXT NOT NULL,body TEXT NOT NULL,created_at INTEGER NOT NULL,request_key TEXT NOT NULL,UNIQUE(author,request_key));
CREATE TABLE sf_com_reads(thread_id TEXT NOT NULL,user_id TEXT NOT NULL,last_id INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(thread_id,user_id));
CREATE TABLE sf_com_preferences(user_id TEXT PRIMARY KEY,notify INTEGER NOT NULL DEFAULT 0);
CREATE TABLE sf_chart_outcomes(batch_id TEXT PRIMARY KEY,chart_key TEXT NOT NULL,edition TEXT NOT NULL,state TEXT NOT NULL,summary TEXT NOT NULL,diagnostic TEXT,updated_at INTEGER NOT NULL,notified INTEGER NOT NULL DEFAULT 0);
CREATE TABLE sf_player_queue_leases(user_id TEXT NOT NULL,client_id TEXT NOT NULL,source_album_id TEXT NOT NULL DEFAULT '',queue_hash TEXT NOT NULL,seq INTEGER NOT NULL,updated_at INTEGER NOT NULL,expires_at INTEGER NOT NULL,PRIMARY KEY(user_id,client_id));
CREATE TABLE sf_player_queue_items(user_id TEXT NOT NULL,client_id TEXT NOT NULL,track_id TEXT NOT NULL,PRIMARY KEY(user_id,client_id,track_id));
CREATE TABLE sf_chart_retire_pending(album_id TEXT NOT NULL,track_id TEXT NOT NULL,created_at INTEGER NOT NULL,not_before INTEGER NOT NULL,PRIMARY KEY(album_id,track_id));
CREATE TABLE sf_chart_copies(user_id TEXT NOT NULL,request_key TEXT NOT NULL,album_id TEXT NOT NULL,chart_key TEXT NOT NULL,created_at INTEGER NOT NULL,PRIMARY KEY(user_id,request_key));
CREATE TABLE favorite_albums(user_id TEXT NOT NULL,album_id TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(user_id,album_id));
CREATE INDEX idx_tracks_album_position ON tracks(album_id, position);
CREATE INDEX idx_tracks_name ON tracks(name COLLATE NOCASE);
CREATE INDEX idx_albums_title ON albums(title COLLATE NOCASE);
CREATE INDEX idx_albums_artist ON albums(artist COLLATE NOCASE);
CREATE INDEX idx_sessions_user_expiry ON sessions(user_id, expires_at);
CREATE INDEX idx_sessions_expiry ON sessions(expires_at);
CREATE INDEX idx_lyrics_expiry ON lyrics_cache(expires_at);
CREATE INDEX idx_auth_sessions_expiry ON auth_sessions(expires_at);
CREATE INDEX idx_auth_attempts_key_time ON auth_login_attempts(attempt_key, attempted_at, success);
CREATE INDEX idx_recent_user_played ON recent_tracks(user_id,played_at DESC);
CREATE INDEX idx_playlists_owner ON playlists(owner_id,updated_at DESC);
CREATE INDEX idx_audio_edit_jobs_user ON audio_edit_jobs(user_id,created_at);
CREATE UNIQUE INDEX idx_audio_edit_jobs_active_track ON audio_edit_jobs(track_id) WHERE state IN ("queued","running");
CREATE INDEX sfq_pending ON sf_processing_jobs(state,created_at);
CREATE INDEX sf_refs_source ON sf_album_track_refs(source_album_id,track_id);
CREATE INDEX sf_devices_user ON sf_devices(user_id,last_seen);
CREATE INDEX sf_usage_minute_time ON sf_usage_minute(minute);
CREATE INDEX sf_usage_daily_day ON sf_usage_daily(day);
CREATE INDEX sf_usage_global_peak ON sf_usage_global(downloaded DESC);
CREATE INDEX sf_sessions_token ON sessions(refresh_token_hash);
CREATE INDEX sf_sessions_user ON sessions(user_id,expires_at);
CREATE INDEX sf_sessions_expiry ON sessions(expires_at);
CREATE INDEX sflp_owner ON sf_loudness_progress_batches(user_id,id);
CREATE INDEX sfmn_batches_user ON sf_mornye_batches(user_id,created_at);
CREATE INDEX sfda_tasks_pending ON sf_desktop_tasks(request_id,state,created_at);
CREATE INDEX sf_chart_entry_identity ON sf_chart_entries(identity);
CREATE INDEX sf_com_pending ON sf_com_recipients(user_id,confirmed_at,notice_id);
CREATE INDEX sf_com_thread_owner ON sf_com_threads(user_id,updated_at);
CREATE INDEX sf_com_messages_thread ON sf_com_messages(thread_id,id);
CREATE INDEX sf_chart_outcome_recent ON sf_chart_outcomes(updated_at);
CREATE INDEX sf_queue_track ON sf_player_queue_items(track_id,user_id,client_id);
CREATE INDEX idx_favorite_albums_user ON favorite_albums(user_id,created_at DESC);
CREATE INDEX idx_favorite_albums_album ON favorite_albums(album_id);
CREATE TRIGGER protect_primary_admin_update
         BEFORE UPDATE OF id, role, is_active, can_manage_albums ON users
         FOR EACH ROW
         WHEN OLD.id = '759409f6084a7db8f7e9de69f2de38b3'
          AND (
            NEW.id <> '759409f6084a7db8f7e9de69f2de38b3'
            OR NEW.role <> 'admin'
            OR NEW.is_active <> 1
            OR NEW.can_manage_albums <> 1
          )
         BEGIN
            SELECT RAISE(ABORT, 'La cuenta administradora principal está protegida');
         END;
CREATE TRIGGER protect_primary_admin_delete
         BEFORE DELETE ON users
         FOR EACH ROW
         WHEN OLD.id = '759409f6084a7db8f7e9de69f2de38b3'
         BEGIN
            SELECT RAISE(ABORT, 'La cuenta administradora principal no se puede eliminar');
         END;
CREATE TRIGGER sf_worker_log_insert AFTER INSERT ON sf_desktop_tasks WHEN (SELECT enabled FROM sf_worker_log_options WHERE id=1)=1 BEGIN INSERT INTO sf_worker_task_events(task_id,state,created_at) VALUES(NEW.id,NEW.state,NEW.updated_at); DELETE FROM sf_worker_task_events WHERE id NOT IN (SELECT id FROM sf_worker_task_events ORDER BY id DESC LIMIT 1000); END;
CREATE TRIGGER sf_worker_log_update AFTER UPDATE OF state ON sf_desktop_tasks WHEN OLD.state!=NEW.state AND (SELECT enabled FROM sf_worker_log_options WHERE id=1)=1 BEGIN INSERT INTO sf_worker_task_events(task_id,state,created_at) VALUES(NEW.id,NEW.state,NEW.updated_at); DELETE FROM sf_worker_task_events WHERE id NOT IN (SELECT id FROM sf_worker_task_events ORDER BY id DESC LIMIT 1000); END;
