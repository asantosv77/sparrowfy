<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
lab_auth_require_album_manager();
require_once __DIR__.'/lib/control_log.php';
sf_control_request('album.create');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST requerido']);
    exit;
}

try { lab_require_same_origin_post(); } catch (Throwable $e) { lab_json_error($e, 403); }

try {
    lab_ensure_data_dirs();
    $title = trim((string) ($_POST['title'] ?? '')) ?: 'Nuevo álbum';
    $artist = trim((string) ($_POST['artist'] ?? ''));
    require_once __DIR__.'/lib/album_identity.php';
    $nameLock=fopen(LAB_DATA.'/.album-create.lock','c');
    if(!$nameLock||!flock($nameLock,LOCK_EX))throw new RuntimeException('No se pudo bloquear la creación');
    try {
    $existing=sf_album_find_named($title);
    if($existing && (string)($_POST['existing_album_id']??'')!==(string)$existing['id']){
        http_response_code(409);
        echo json_encode(['error'=>'Ya existe una colección con ese nombre.','code'=>'album_exists','existing_album'=>['id'=>$existing['id'],'title'=>$existing['title'],'tracks_count'=>count($existing['tracks']??[])]],JSON_UNESCAPED_UNICODE);
        return;
    }

    $album = $existing ?? [
        'id' => lab_id(),
        'title' => $title,
        'artist' => $artist,
        'cover' => null,
        'cover_layout' => ['x' => 50, 'y' => 50, 'zoom' => 100],
        'tracks' => [],
        'created_at' => date('c'),
    ];
    if(!$existing) lab_save_album($album);
    } finally {flock($nameLock,LOCK_UN);fclose($nameLock);}

    sf_control_event('album.created',['album_id'=>$album['id']]);
    // Verificar que se puede releer (mismo proceso)
    $check = lab_load_album($album['id']);
    if (!$check) {
        throw new RuntimeException(
            'El álbum se escribió pero no se pudo releer. Ruta esperada: ' . lab_album_path($album['id'])
        );
    }

    lab_album_hls_dir($album['id'], $album['title']);

    echo json_encode([
        'success' => true,
        'reused' => $existing!==null,
        'album' => $check,
        'album_id' => $check['id'],
        'path' => lab_album_path($check['id']),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    sf_control_error('album.create.failed',$e);
    error_log('Album creation failed: '.get_class($e).' '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().' trace='.$e->getTraceAsString());
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
