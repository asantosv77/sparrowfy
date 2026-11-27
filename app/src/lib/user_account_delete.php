<?php
declare(strict_types=1);

/** Deletes one account without deleting tracks, albums or other accounts' playlists. */
function sf_delete_account(PDO $db, string $actor, string $id, string $data, string $sessionDir): void
{
    if ($id === '' || strlen($id) > 128) throw new DomainException('Identificador de cuenta inválido.');
    if (lab_auth_is_protected_admin_id($id)) throw new DomainException('La cuenta administradora principal no se puede eliminar.');
    if ($id === $actor) throw new DomainException('No puedes eliminar tu propia cuenta mientras la estás usando.');

    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA secure_delete=ON');
    $tables = array_fill_keys($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN), true);
    $find = static function (string $query, array $args = []) use ($db): array {
        $statement = $db->prepare($query);
        $statement->execute($args);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    };
    $write = static function (string $query, array $args) use ($db): void {
        $db->prepare($query)->execute($args);
    };
    $validate = static function () use ($actor, $id, $find): void {
        if (!$find("SELECT id FROM users WHERE id=? AND role='admin' AND is_active=1", [$actor])) {
            throw new DomainException('Solo un administrador activo puede eliminar cuentas.');
        }
        if (!$find('SELECT id FROM users WHERE id=?', [$id])) throw new DomainException('La cuenta ya no existe.');
    };
    $idle = static function () use ($id, $find, $tables): void {
        foreach (['sf_processing_jobs','audio_edit_jobs'] as $table) {
            if (isset($tables[$table]) && $find("SELECT id FROM $table WHERE user_id=? AND state='running' LIMIT 1", [$id])) {
                throw new DomainException('La cuenta tiene un trabajo en curso. Espera a que termine y vuelve a intentar.');
            }
        }
        if (isset($tables['sf_desktop_tasks'],$tables['sf_worker_requests']) &&
            $find("SELECT t.id FROM sf_desktop_tasks t JOIN sf_worker_requests r ON r.id=t.request_id
                   WHERE r.user_id=? AND t.state IN ('leased','uploading','validating') AND t.lease_until>=? LIMIT 1", [$id,time()])) {
            throw new DomainException('El worker de la cuenta tiene un trabajo en curso. Vuelve a intentar cuando termine.');
        }
    };

    // Stop new logins first. A failed disk cleanup can be retried for the same disabled account.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $validate();
        $idle();
        $write('UPDATE users SET is_active=0 WHERE id=?', [$id]);
        foreach (['sf_processing_jobs','audio_edit_jobs'] as $table) {
            if (isset($tables[$table])) $write("UPDATE $table SET state='failed' WHERE user_id=? AND state='queued'", [$id]);
        }
        if (isset($tables['sf_desktop_tasks'],$tables['sf_worker_requests'])) {
            $write("UPDATE sf_desktop_tasks SET state='failed' WHERE state='queued' AND request_id IN
                    (SELECT id FROM sf_worker_requests WHERE user_id=?)", [$id]);
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }

    $jobs = [];
    foreach (['sf_processing_jobs','audio_edit_jobs'] as $table) {
        if (isset($tables[$table])) array_push($jobs, ...$find("SELECT id FROM $table WHERE user_id=?", [$id]));
    }
    $requestIds = isset($tables['sf_worker_requests']) ? $find('SELECT id FROM sf_worker_requests WHERE user_id=?', [$id]) : [];
    $desktopIds = isset($tables['sf_desktop_tasks'],$tables['sf_worker_requests'])
        ? $find('SELECT t.id FROM sf_desktop_tasks t JOIN sf_worker_requests r ON r.id=t.request_id WHERE r.user_id=?', [$id]) : [];

    sf_delete_account_files($id, $data, $sessionDir, $jobs, $requestIds, $desktopIds);
    $uploadRoot = realpath($data.'/processing_jobs');
    if ($uploadRoot !== false && isset($tables['sf_processing_jobs'])) {
        foreach ($find("SELECT payload FROM sf_processing_jobs WHERE user_id=? AND operation='upload'", [$id]) as $payload) {
            $upload = json_decode((string)$payload,true)['file'] ?? null;
            if (!is_string($upload) || !preg_match('/^[a-f0-9]{32}\.upload$/D',basename($upload)) ||
                realpath(dirname($upload))!==$uploadRoot) continue;
            if ((is_file($upload)||is_link($upload)) && !unlink($upload)) throw new RuntimeException('No se pudo borrar una subida temporal.');
        }
    }

    $db->exec('BEGIN IMMEDIATE');
    try {
        $validate();
        if ($find('SELECT id FROM users WHERE id=? AND is_active=1', [$id])) {
            throw new DomainException('La cuenta se reactivó durante la eliminación. Vuelve a intentar.');
        }
        $idle();

        $relations = [
            'sf_device_remember' => [
                'sf_devices' => 'device_id IN (SELECT id FROM sf_devices WHERE user_id=?)',
                'sf_remember_tokens' => 'selector IN (SELECT selector FROM sf_remember_tokens WHERE user_id=?)',
            ],
            'sf_worker_task_events' => ['sf_desktop_tasks' => 'task_id IN (SELECT t.id FROM sf_desktop_tasks t JOIN sf_worker_requests r ON r.id=t.request_id WHERE r.user_id=?)'],
            'sf_desktop_tasks' => ['sf_worker_requests' => 'request_id IN (SELECT id FROM sf_worker_requests WHERE user_id=?)'],
            'sf_desktop_agents' => ['sf_worker_requests' => 'request_id IN (SELECT id FROM sf_worker_requests WHERE user_id=?)'],
            'sf_worker_access_events' => ['sf_worker_requests' => 'request_id IN (SELECT id FROM sf_worker_requests WHERE user_id=?)'],
            'sf_com_messages' => ['sf_com_threads' => 'thread_id IN (SELECT id FROM sf_com_threads WHERE user_id=?)'],
            'sf_com_reads' => ['sf_com_threads' => 'thread_id IN (SELECT id FROM sf_com_threads WHERE user_id=?)'],
            'sf_com_recipients' => ['sf_com_notices' => 'notice_id IN (SELECT id FROM sf_com_notices WHERE author=?)'],
            'sf_playlist_imports' => ['playlists' => 'source_id IN (SELECT id FROM playlists WHERE owner_id=?)'],
        ];
        foreach ($relations as $table => $predicates) {
            foreach ($predicates as $requires => $predicate) {
                if (!isset($tables[$table],$tables[$requires])) continue;
                if ($table==='sf_worker_task_events' && !isset($tables['sf_worker_requests'])) continue;
                $write("DELETE FROM $table WHERE $predicate", [$id]);
            }
        }

        // Foreign-key cascades handle owned playlists, favorites and other newer tables.
        // Legacy and operational tables without a foreign key need explicit cleanup.
        $userTables = [
            'sessions','favorites','recent_tracks','favorite_albums','user_suggestions','user_preferences',
            'user_theme_preferences','user_tip_preferences','user_ui_preferences','sf_client_memory_preferences',
            'sf_remember_tokens','sf_devices','sf_device_epochs','sf_usage_daily','sf_usage_minute',
            'sf_usage_presence','sf_player_queue_items','sf_player_queue_leases','sf_volume_preferences',
            'sf_audio_download_permissions','sf_loudness_progress_batches','sf_mornye_batches','sf_chart_copies',
            'sf_worker_requests','sf_com_recipients','sf_com_threads','sf_com_reads','sf_com_preferences',
            'audio_edit_jobs','sf_processing_jobs','sf_playlist_imports',
        ];
        foreach ($userTables as $table) {
            if (isset($tables[$table])) $write("DELETE FROM $table WHERE user_id=?", [$id]);
        }
        foreach ([
            'playlists'=>'owner_id','playlist_shares'=>'shared_with_user_id','sf_com_messages'=>'author',
            'sf_com_notices'=>'author','sf_worker_access_events'=>'actor_id','sf_album_deletion_requests'=>'requested_by',
        ] as $table => $column) {
            if (isset($tables[$table])) $write("DELETE FROM $table WHERE $column=?", [$id]);
        }
        if (isset($tables['sf_worker_requests'])) {
            $write('UPDATE sf_worker_requests SET reviewed_by=NULL WHERE reviewed_by=?', [$id]);
        }
        if (isset($tables['sf_album_deletion_requests'])) {
            $write('UPDATE sf_album_deletion_requests SET resolved_by=NULL,resolved_name=NULL WHERE resolved_by=?', [$id]);
        }
        if (isset($tables['auth_sessions'])) {
            foreach ($db->query('SELECT session_id,session_data FROM auth_sessions')->fetchAll(PDO::FETCH_ASSOC) as $session) {
                if (sf_deleted_account_owns_session($session['session_data'], $id)) {
                    $write('DELETE FROM auth_sessions WHERE session_id=?', [$session['session_id']]);
                }
            }
        }
        $write('DELETE FROM users WHERE id=?', [$id]);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function sf_deleted_account_owns_session(string $payload, string $id): bool
{
    if ($payload === '') return false;
    if (str_starts_with($payload, 'a:')) {
        $session = @unserialize($payload, ['allowed_classes'=>false]);
        return is_array($session) && array_key_exists('user_id',$session) && (string)$session['user_id']===$id;
    }
    $user = preg_quote('user_id|'.serialize($id), '~');
    if (preg_match('~(?:^|[;}])'.$user.'~', $payload)) return true;
    return ctype_digit($id) && preg_match('~(?:^|[;}])user_id\\|i:'.preg_quote($id,'~').';~', $payload) === 1;
}

function sf_delete_account_files(string $id, string $data, string $sessionDir, array $jobs, array $requests, array $desktop): void
{
    $jobs = array_fill_keys(array_filter([...$jobs,...$desktop], static fn($job)=>is_string($job)&&preg_match('/^[a-f0-9]{32}$/D',$job)), true);
    foreach (glob(rtrim($sessionDir,'/').'/sess_*', GLOB_NOSORT) ?: [] as $file) {
        if (is_link($file) || !is_file($file)) continue;
        $session = file_get_contents($file);
        if ($session === false) {
            if (is_file($file)) throw new RuntimeException('No se pudieron revisar las sesiones web.');
            continue;
        }
        if (!sf_deleted_account_owns_session($session,$id)) continue;
        $handle = @fopen($file,'r+');
        if (!$handle) {
            if (!is_file($file)) continue;
            throw new RuntimeException('No se pudo borrar una sesión web.');
        }
        try {
            if (!flock($handle,LOCK_EX|LOCK_NB)) throw new DomainException('Una sesión de la cuenta está terminando una solicitud. Vuelve a intentar.');
            $session = stream_get_contents($handle);
            if ($session === false) throw new RuntimeException('No se pudo leer la sesión web.');
            if (sf_deleted_account_owns_session($session,$id) && (!ftruncate($handle,0) || !unlink($file))) {
                throw new RuntimeException('No se pudo borrar la sesión web.');
            }
        } finally { fclose($handle); }
    }

    $logs = [
        ['control-log','.lock','events.jsonl*'],
        ['media-log','.lock','events.jsonl*'],
        ['playback-diagnostics','.write.lock','playback-*.jsonl'],
        ['usage-spool','.rollup.lock','*.ndjson,pending/*.ndjson'],
    ];
    foreach ($logs as [$folder,$lockName,$patterns]) {
        $dir = $data.'/'.$folder;
        if (!is_dir($dir) || is_link($dir)) continue;
        $lock = fopen($dir.'/'.$lockName,'c');
        if (!$lock) throw new RuntimeException('No se pudo bloquear el registro de la cuenta.');
        try {
            if (!flock($lock,LOCK_EX)) throw new RuntimeException('No se pudo bloquear el registro de la cuenta.');
            $writer = $folder==='usage-spool' ? fopen($dir.'/.lock','c') : null;
            try {
                if ($folder==='usage-spool' && (!$writer || !flock($writer,LOCK_EX))) throw new RuntimeException('El registro de uso está ocupado.');
                foreach (explode(',',$patterns) as $pattern) {
                    foreach (glob($dir.'/'.$pattern,GLOB_NOSORT) ?: [] as $file) {
                        if (is_file($file)&&!is_link($file)) sf_filter_account_log($file,$id,$jobs);
                    }
                }
            } finally { if ($writer) fclose($writer); }
        } finally { fclose($lock); }
    }

    foreach (array_keys($jobs) as $job) {
        foreach (['m4a','mp3','m3u8','part'] as $extension) {
            $file = $data.'/audio-downloads/'.$job.'.'.$extension;
            if ((is_file($file)||is_link($file)) && !unlink($file)) throw new RuntimeException('No se pudo borrar una descarga temporal.');
        }
    }
    foreach ($requests as $request) {
        if (!is_string($request)||!preg_match('/^[a-f0-9]{32}$/D',$request)) continue;
        $file = $data.'/desktop-worker-jobs/node-'.$request.'.json';
        if ((is_file($file)||is_link($file)) && !unlink($file)) throw new RuntimeException('No se pudieron borrar las métricas del worker.');
    }
    foreach ($desktop as $job) {
        if (!isset($jobs[$job])) continue;
        $dir = $data.'/desktop-worker-jobs/'.$job;
        if (!file_exists($dir) && !is_link($dir)) continue;
        require_once __DIR__.'/desktop_agent.php';
        sfda_remove_files($job);
        if (file_exists($dir)||is_link($dir)) throw new RuntimeException('No se pudieron borrar los temporales del worker.');
    }
}

function sf_filter_account_log(string $file, string $id, array $jobs): void
{
    $input = fopen($file,'rb');
    if (!$input) throw new RuntimeException('No se pudo abrir el registro para depurarlo.');
    $temp = tempnam(dirname($file),'.purge-');
    $output = $temp!==false ? fopen($temp,'wb') : false;
    try {
        if (!$output) throw new RuntimeException('No se pudo preparar la depuración del registro.');
        $changed = false;
        while (($line=fgets($input))!==false) {
            $row = json_decode($line,true);
            $owner = is_array($row) ? ($row['user_id']??$row['user']??$row[1]??null) : null;
            $job = is_array($row) ? ($row['job_id']??$row['task_id']??null) : null;
            if ((is_scalar($owner)&&(string)$owner===$id) || (is_string($job)&&isset($jobs[$job]))) {
                $changed = true;
            } elseif (fwrite($output,$line)!==strlen($line)) {
                throw new RuntimeException('No se pudo guardar el registro depurado.');
            }
        }
        if (!feof($input)||!fflush($output)) throw new RuntimeException('No se pudo completar la depuración del registro.');
        fclose($output);
        $output = false;
        if ($changed && (!chmod($temp,fileperms($file)&0777) || !rename($temp,$file))) {
            throw new RuntimeException('No se pudo publicar el registro depurado.');
        }
    } finally {
        fclose($input);
        if ($output) fclose($output);
        if ($temp!==false&&is_file($temp)) unlink($temp);
    }
}
