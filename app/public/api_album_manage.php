<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
lab_auth_require_album_manager();
require __DIR__ . '/lib/delete_safety.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST requerido']);
    exit;
}

try { lab_require_same_origin_post(); } catch (Throwable $e) { lab_json_error($e, 403); }
if(!lab_auth_verify_csrf((string)($_POST['csrf_token']??''))){http_response_code(403);echo json_encode(['error'=>'Recarga la página para renovar la sesión']);exit;}


try {
    lab_ensure_data_dirs();
    $action = (string) ($_POST['action'] ?? '');
    // Gestores pueden eliminar pistas; eliminar colecciones completas requiere administrador.
    if ($action==='delete') lab_auth_require_admin();
    $albumId = strtolower(trim((string) ($_POST['album_id'] ?? '')));
    if ($action !== 'list' && !preg_match('/^[a-f0-9]{16}$/D', $albumId)) throw new RuntimeException('Identificador de colección inválido');
    if ($action === 'delete' && $albumId === 'fffffffffffffffe') throw new RuntimeException('Las pistas sueltas se gestionan individualmente');
    require_once __DIR__.'/lib/track_reuse.php';
    $reuseLock=sf_reuse_lock();
    register_shutdown_function(static function()use($reuseLock,$albumId){if(!lab_load_album($albumId))sf_reuse_db()->prepare('DELETE FROM sf_album_track_refs WHERE album_id=?')->execute([$albumId]);flock($reuseLock,LOCK_UN);fclose($reuseLock);});

    if($action==='delete_track'){
      $reuseTid=(string)($_POST['track_id']??'');
      $deleteLooseMemberships=false;
      if($albumId==='fffffffffffffffe'){
        $db=sf_reuse_db();
        $q=$db->prepare('SELECT COUNT(*) FROM sf_album_track_refs WHERE source_album_id=? AND track_id=?');$q->execute([$albumId,$reuseTid]);
        if(!(int)$q->fetchColumn()){
          $uses=0;foreach(['favorites','playlist_tracks'] as $table){$q=$db->prepare("SELECT COUNT(*) FROM $table WHERE track_id=?");$q->execute([$reuseTid]);$uses+=(int)$q->fetchColumn();}
          if($uses){
            if(($_POST['confirm_remove_memberships']??'')!=='1'){
              http_response_code(409);echo json_encode(['error'=>'Esta pista sin colección está en favoritos o listas de reproducción. Eliminarla definitivamente también la quitará de esos favoritos y listas. Las pistas con otros identificadores no se modificarán.','requires_membership_confirmation'=>true],JSON_UNESCAPED_UNICODE);exit;
            }
            $deleteLooseMemberships=true;
          }
        }
      }
      if(!$deleteLooseMemberships)sf_reuse_preserve_before_delete($albumId,$reuseTid);
      $remaining=array_column(lab_load_album($albumId)['tracks']??[],'id');
      if(!in_array($reuseTid,$remaining,true)){echo json_encode(['success'=>true,'ok'=>true,'preserved'=>true,'track_id'=>$reuseTid,'remaining_tracks'=>count($remaining),'warning'=>null,'message'=>'Quitada de la colección. Conservada en la biblioteca porque sigue en uso.']);exit;}
      sf_reuse_guard($albumId,$reuseTid);
    }
    $album = lab_load_album($albumId);
    if (!$album && $action !== 'list') {
        throw new RuntimeException('Álbum no encontrado');
    }

    // Aviso previo al borrado: cuántos usuarios tienen la colección en favoritos.
    if ($action === 'favorites_preview') {
        require_once __DIR__.'/lib/favorite_albums.php';
        $favDb = lab_auth_pdo();
        $count = sf_fav_albums_count($favDb, $albumId);
        $users = $count > 0 ? sf_fav_albums_usernames($favDb, $albumId, 8) : [];
        echo json_encode(['success' => true, 'count' => $count, 'users' => $users], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'request_delete') {
        lab_auth_require_album_manager();
        if ($albumId === 'fffffffffffffffe') throw new RuntimeException('Las pistas sin asignar se gestionan individualmente.');
        require_once __DIR__.'/lib/album_deletion.php';
        $acct = lab_auth_current_account();
        sf_delreq_request($albumId, (string)($acct['id'] ?? ''), (string)($acct['username'] ?? ''));
        echo json_encode(['success' => true, 'pending' => true, 'album_id' => $albumId], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'cancel_delete_request') {
        require_once __DIR__.'/lib/album_deletion.php';
        $acct = lab_auth_current_account();
        if (lab_auth_is_admin()) {
            sf_delreq_resolve($albumId, 'restored', (string)($acct['id'] ?? ''), (string)($acct['username'] ?? ''));
        } else {
            sf_delreq_cancel($albumId, (string)($acct['id'] ?? ''));
        }
        echo json_encode(['success' => true, 'restored' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'restore_delete') {
        lab_auth_require_admin();
        require_once __DIR__.'/lib/album_deletion.php';
        $acct = lab_auth_current_account();
        sf_delreq_resolve($albumId, 'restored', (string)($acct['id'] ?? ''), (string)($acct['username'] ?? ''));
        echo json_encode(['success' => true, 'restored' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'rename_track') {
        $trackId = strtolower(trim((string)($_POST['track_id'] ?? '')));
        $newName = trim((string)($_POST['name'] ?? ''));

        if (!preg_match('/^[a-f0-9]{16}$/', $trackId)) {
            throw new RuntimeException('ID de pista inválido.');
        }
        if ($newName === '' || preg_match('//u', $newName) !== 1) {
            throw new RuntimeException('El nombre no puede estar vacío y debe ser texto UTF-8 válido.');
        }
        $nameLength = function_exists('mb_strlen') ? mb_strlen($newName, 'UTF-8') : strlen($newName);
        if ($nameLength > 160) {
            throw new RuntimeException('El nombre no puede superar 160 caracteres.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $newName)) {
            throw new RuntimeException('El nombre contiene caracteres de control no permitidos.');
        }

        $trackIndex = null;
        foreach (($album['tracks'] ?? []) as $index => $candidate) {
            if (is_array($candidate) &&
                strtolower((string)($candidate['id'] ?? '')) === $trackId) {
                $trackIndex = $index;
                break;
            }
        }
        if ($trackIndex === null) {
            throw new RuntimeException('La pista ya no existe en este álbum. Recarga la página.');
        }

        $oldName = (string)($album['tracks'][$trackIndex]['name'] ?? '');
        if ($newName === $oldName && !array_intersect(['title','artist','album','genre','year'], array_keys($_POST))) {
            echo json_encode(['success' => true, 'track_id' => $trackId, 'name' => $newName]);
            exit;
        }

        $albumFile = LAB_ALBUMS . '/' . $albumId . '/album.json';
        if (!is_file($albumFile) || !is_readable($albumFile)) {
            throw new RuntimeException('album.json no existe o no se puede leer. No se modificó nada.');
        }

        hml_delete_probe_directory(dirname($albumFile));
        $pdo = hml_delete_sqlite_open_and_validate($albumId, $album);
        $originalAlbum = $album;
        $jsonChanged = false;

        try {
            $album['tracks'][$trackIndex]['name'] = $newName;
            foreach (['title','artist','album','genre','year'] as $field) {
                if (!array_key_exists($field, $_POST)) continue;
                $value = trim((string)$_POST[$field]);
                if (preg_match('//u', $value) !== 1 || strlen($value) > 640 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                    throw new RuntimeException('Metadato inválido: ' . $field);
                }
                if ($field === 'year' && $value !== '' && (!ctype_digit($value) || (int)$value > 9999)) {
                    throw new RuntimeException('Año inválido');
                }
                $album['tracks'][$trackIndex][$field] = $value;
            }

            hml_delete_atomic_save_album($album);
            $jsonChanged = true;

            if ($pdo instanceof PDO) {
                $stmt = $pdo->prepare(
                    'UPDATE tracks SET name = ?, updated_at = ? WHERE id = ? AND album_id = ?'
                );
                $stmt->execute([$newName, gmdate('c'), $trackId, $albumId]);

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        'SQLite no actualizó exactamente una pista; se restaurará el nombre anterior.'
                    );
                }
                require_once __DIR__.'/lib/sqlite_catalog_sync.php';
                lab_sqlite_sync_album($album,$pdo);
                $pdo->commit();
            }
        } catch (Throwable $e) {
            $rollbackErrors = hml_delete_rollback(
                $pdo,
                [],
                $jsonChanged ? $originalAlbum : null
            );
            $suffix = $rollbackErrors
                ? ' Revisa la recuperación manual: ' . implode('; ', $rollbackErrors)
                : ' Se intentó restaurar el estado anterior.';
            throw new RuntimeException($e->getMessage() . $suffix, 0, $e);
        }

        $warning = null;
        if (!($pdo instanceof PDO)) {
            $warning = 'SQLite no está configurada; solo se actualizó album.json (modo legado).';
        }

        echo json_encode([
            'success' => true,
            'track_id' => $trackId,
            'name' => $newName,
            'warning' => $warning,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    if ($action === 'delete_track') {
        $trackId = strtolower(trim((string) ($_POST['track_id'] ?? '')));
        if (!preg_match('/^[a-f0-9]{16}$/', $trackId)) {
            throw new RuntimeException('ID de pista inválido.');
        }

        $trackIndex = null;
        $track = null;
        foreach (($album['tracks'] ?? []) as $index => $candidate) {
            if (is_array($candidate) && strtolower((string) ($candidate['id'] ?? '')) === $trackId) {
                $trackIndex = $index;
                $track = $candidate;
                break;
            }
        }
        if ($trackIndex === null || !is_array($track)) {
            throw new RuntimeException('La pista ya no existe en este álbum. Recarga la página.');
        }

        // PRE-FLIGHT: validar todo antes de modificar JSON, SQLite o HLS.
        $albumFile = LAB_ALBUMS . '/' . $albumId . '/album.json';
        if (!is_file($albumFile) || !is_readable($albumFile)) {
            throw new RuntimeException('album.json no existe o no se puede leer. No se eliminó nada.');
        }
        hml_delete_probe_directory(dirname($albumFile));
        $jobDir = hml_delete_validate_track_dir($albumId, $track);
        $pdo = hml_delete_sqlite_open_and_validate($albumId, $album);
        $quarantines = [];
        $originalAlbum = $album;
        array_splice($album['tracks'], (int)$trackIndex, 1);
        $jsonChanged = false;

        try {
            // Renombrar es reversible y se hace en el mismo filesystem.
            $hlsTrash = hml_delete_quarantine($jobDir);
            $quarantines[] = ['original' => $jobDir, 'quarantine' => $hlsTrash];

            hml_delete_atomic_save_album($album);
            $jsonChanged = true;

            if ($pdo instanceof PDO) {
                if(!empty($deleteLooseMemberships)){
                    foreach(['favorites','playlist_tracks'] as $table){$q=$pdo->prepare("DELETE FROM $table WHERE track_id=?");$q->execute([$trackId]);}
                }
                $delRenditions = $pdo->prepare('DELETE FROM track_renditions WHERE track_id = ?');
                $delRenditions->execute([$trackId]);
                $delTrack = $pdo->prepare('DELETE FROM tracks WHERE id = ? AND album_id = ?');
                $delTrack->execute([$trackId, $albumId]);
                if ($delTrack->rowCount() !== 1) {
                    throw new RuntimeException('SQLite no eliminó exactamente una pista; se restaurará el estado anterior.');
                }
                require_once __DIR__.'/lib/sqlite_catalog_sync.php';
                lab_sqlite_sync_album($album,$pdo);

                // CORRECCIÓN: cargar favorite_albums.php ANTES de usar la función
                require_once __DIR__.'/lib/favorite_albums.php';
                // Si la colección se quedó sin pistas visibles deja de tener sentido como atajo.
                if (!sf_fav_albums_has_tracks($pdo, $albumId)) {
                    sf_fav_albums_remove($pdo, $albumId);
                }
                $pdo->commit();
            }
        } catch (Throwable $e) {
            $rollbackErrors = hml_delete_rollback($pdo, $quarantines, $jsonChanged ? $originalAlbum : null);
            $suffix = $rollbackErrors ? ' Además, revisa recuperación manual: ' . implode('; ', $rollbackErrors) : ' Se intentó restaurar el estado anterior.';
            throw new RuntimeException($e->getMessage() . $suffix, 0, $e);
        }

        $warning = null;
        foreach ($quarantines as $q) {
            try { hml_delete_tree($q['quarantine']); }
            catch (Throwable $cleanupError) {
                $warning = 'La eliminación lógica terminó, pero quedó contenido en cuarentena para limpieza manual: ' . $q['quarantine'] . ' (' . $cleanupError->getMessage() . ')';
                break;
            }
        }
        if (!($pdo instanceof PDO)) {
            $warning = trim(($warning ? $warning . ' ' : '') . 'SQLite no está configurada; solo se actualizó album.json (modo legado).');
        }

        echo json_encode([
            'success' => true,
            'track_id' => $trackId,
            'remaining_tracks' => count($album['tracks'] ?? []),
            'warning' => $warning,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    if ($action === 'delete') {
        require_once __DIR__.'/lib/album_delete_transaction.php';
        $warning=sf_delete_album_with_preservation($albumId,$album);
        echo json_encode(['success'=>true,'warning'=>$warning],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    if ($action === 'set_cover_url') {
        $url = trim((string) ($_POST['cover_url'] ?? ''));
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || (int)($parts['port'] ?? 443) !== 443 || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('La portada debe usar una URL HTTPS válida.');
        }
        // Evita hosts locales/IP privadas y no sigue redirecciones (SSRF básico).
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('El host de la portada no es público.');
        }
        if (!function_exists('curl_init')) throw new RuntimeException('Se requiere la extensión cURL para descargar portadas externas de forma segura.');
        $ch = curl_init($url);
        // Pin the validated IPv4 address: cURL must not resolve DNS again.
        curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':443:' . $ip]);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_PROXY, '');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE => 8 * 1024 * 1024,
            CURLOPT_USERAGENT => 'HLS-Music-Lab/1.2',
            CURLOPT_HTTPHEADER => ['Accept: image/jpeg,image/png,image/webp,image/gif'],
        ]);
        $bin = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || !is_string($bin) || $bin === '' || strlen($bin) > 8 * 1024 * 1024) {
            throw new RuntimeException('No se pudo descargar la portada o supera 8 MiB.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bin) ?: '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || @getimagesizefromstring($bin) === false) {
            throw new RuntimeException('La URL no devolvió una imagen válida.');
        }
        $ext = match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            default => 'jpg',
        };
        $fname = 'cover.' . $ext;
        $dest = LAB_ALBUMS . '/' . $albumId . '/' . $fname;
        if (file_put_contents($dest, $bin) === false) {
            throw new RuntimeException('No se pudo guardar la portada');
        }
        $album['cover'] = $fname;
        if (empty($album['cover_layout'])) {
            $album['cover_layout'] = ['x' => 50, 'y' => 50, 'zoom' => 100];
        }
        // metadatos opcionales del match
        if (!empty($_POST['matched_album'])) {
            $album['matched_album'] = trim((string) $_POST['matched_album']);
        }
        if (!empty($_POST['matched_artist'])) {
            $album['matched_artist'] = trim((string) $_POST['matched_artist']);
        }
        lab_save_album($album);
        require_once __DIR__.'/lib/cover_url.php';
        clearstatcache(true, LAB_ALBUMS.'/'.$albumId.'/'.$fname);
        echo json_encode([
            'success' => true,
            'cover' => sf_cover_url(['id' => $albumId, 'cover' => $fname]),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'set_layout') {
        $album['cover_layout'] = [
            'x' => max(0, min(100, (float) ($_POST['x'] ?? 50))),
            'y' => max(0, min(100, (float) ($_POST['y'] ?? 50))),
            'zoom' => max(50, min(200, (float) ($_POST['zoom'] ?? 100))),
        ];
        lab_save_album($album);
        echo json_encode(['success' => true, 'layout' => $album['cover_layout']]);
        exit;
    }

    throw new RuntimeException('Acción no válida');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
