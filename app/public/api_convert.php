<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
lab_auth_require_album_manager();
require_once __DIR__.'/lib/control_log.php';
sf_control_request('file.upload');
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
    $cfg = lab_load_config();
    $albumId = strtolower(preg_replace('/[^a-f0-9]/', '', (string) ($_POST['album_id'] ?? '')) ?? '');
    if ($albumId === '' || !preg_match('/^[a-f0-9]{16}$/', $albumId)) {
        throw new RuntimeException('album_id inválido o vacío (recibido: ' . substr((string) ($_POST['album_id'] ?? ''), 0, 40) . ')');
    }
    $album = lab_load_album($albumId);
    if (!$album) {
        throw new RuntimeException(
            'Álbum no encontrado (id=' . $albumId . '). ¿Existe ' . lab_album_path($albumId) . '? '
            . 'Crea el álbum de nuevo o revisa permisos de data/albums (usuario www-data).'
        );
    }
    if (empty($_FILES['audio']) || !is_array($_FILES['audio'])) {
        throw new RuntimeException('Sin archivo de audio en la petición');
    }
    $f = $_FILES['audio'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error de subida (código ' . (int) ($f['error'] ?? 0) . ')');
    }
    $max = ((int) $cfg['max_upload_mib']) * 1048576;
    if (($f['size'] ?? 0) > $max) {
        throw new RuntimeException('Archivo demasiado grande');
    }
    $name = (string) ($f['name'] ?? 'track');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, lab_allowed_audio_extensions($cfg), true)) {
        throw new RuntimeException('Extensión no permitida: .' . $ext);
    }
    $tmp = (string) ($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Archivo temporal inválido');
    }

    require_once __DIR__.'/lib/processing_queue.php';
    $account=lab_auth_current_account();
    if(!$account) throw new RuntimeException('Sesión caducada');
    $db=sfq_db();$dir=sfq_dir();$id=bin2hex(random_bytes(16));
    $stored=$dir.'/'.$id.'.upload';
    if(!move_uploaded_file($tmp,$stored)) throw new RuntimeException('No se pudo conservar la subida para la cola');
    @chmod($stored,0600);
    try {
        $job=sfq_add($db,(string)$account['id'],'conversion','upload',$albumId,$name,['file'=>$stored,'filename'=>$name]);
    }catch(Throwable $e){@unlink($stored);throw $e;}
    http_response_code(202);
    echo json_encode(['success'=>true,'queued'=>true,'job_id'=>$job,'state'=>'queued'],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    sf_control_error('request.failed',$e,['operation'=>'file.upload']);
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
