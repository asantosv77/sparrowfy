<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/youtube_url_normalizer.php';

require_once __DIR__.'/lib/control_log.php';
sf_control_request('youtube');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function yt_json(array $payload, int $status = 200): never {
    sf_control_event('youtube.response',['status'=>$status,'success'=>(bool)($payload['success']??false),'imported'=>(int)($payload['imported']??0),'failed'=>(int)($payload['failed']??0)]);
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

require_once __DIR__.'/lib/external_services.php';

if (defined('SFQ_YOUTUBE_FUNCTIONS_ONLY')) return;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') yt_json(['error' => 'POST requerido'], 405);
try { lab_require_same_origin_post(); } catch (Throwable $e) { yt_json(['error' => 'Solicitud no permitida. Recarga la página e inténtalo de nuevo.'], 403); }

if (function_exists('set_time_limit')) @set_time_limit(0);
$cookiePath = null;
register_shutdown_function(static function () use (&$cookiePath): void {
    if (is_string($cookiePath) && $cookiePath !== '' && is_file($cookiePath)) @unlink($cookiePath);
});
try {
    $action = (string)($_POST['action'] ?? '');
    if (in_array($action, ['inspect', 'import'], true)) lab_auth_require_album_manager();
    if (!in_array($action, ['inspect', 'import', 'resolve'], true)) yt_json(['error' => 'Acción no válida.'], 400);
    $cookiePath = yt_cookie_temp();

    if ($action === 'resolve') {
        $url = yt_validate_url((string)($_POST['url'] ?? ''));
        $args = [yt_bin(), '--no-warnings', '--no-playlist', '--no-progress', '--format', 'bestaudio[ext=m4a]/bestaudio', '--get-url', '--socket-timeout', '15', '--retries', '1'];
        if ($cookiePath !== null) { $args[] = '--cookies'; $args[] = $cookiePath; }
        $args[] = $url;
        $r = yt_run($args, 75, 262144);
        if ($r['code'] !== 0) throw new RuntimeException('YouTube no permitió resolver el audio. El video puede requerir acceso, estar bloqueado o no estar disponible.');
        $resolved = trim(explode("\n", trim($r['stdout']))[0] ?? '');
        $parts = parse_url($resolved);
        if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) throw new RuntimeException('yt-dlp no devolvió una URL de audio HTTPS válida.');
        yt_json(['success' => true, 'url' => $resolved, 'source_url' => $url]);
    }

    if ($action === 'inspect') {
        $rawUrls = preg_split('/\R/u', (string)($_POST['urls'] ?? '')) ?: [];
        $urls = [];
        foreach ($rawUrls as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array($line, $urls, true)) $urls[] = $line;
        }
        if (!$urls) yt_json(['error' => 'Pega al menos una URL de YouTube.'], 400);
        if (count($urls) > 10) yt_json(['error' => 'El límite es de 10 enlaces por lote.'], 400);
        $results = [];
        foreach ($urls as $url) {
            try { $results[] = ['ok' => true] + yt_metadata(yt_validate_url($url), $cookiePath); }
            catch (Throwable $e) { $results[] = ['ok' => false, 'url' => $url, 'error' => $e->getMessage()]; }
        }
        yt_json(['success' => true, 'results' => $results]);
    }

    lab_ensure_data_dirs();
    $albumId = strtolower(trim((string)($_POST['album_id'] ?? '')));
    if (!preg_match('/^[a-f0-9]{16}$/', $albumId)) yt_json(['error' => 'Álbum no válido.'], 400);
    $album = lab_load_album($albumId);
    if (!$album) yt_json(['error' => 'No se encontró el álbum.'], 404);
    $tracks = json_decode((string)($_POST['tracks'] ?? ''), true);
    if (!is_array($tracks) || !$tracks || count($tracks) > 10) yt_json(['error' => 'La lista de pistas no es válida (máximo 10).'], 400);
    // Importación asíncrona: cada pista ocupa un puesto en la cola general.
    require_once __DIR__.'/lib/processing_queue.php';
    $account=lab_auth_current_account();if(!$account) throw new RuntimeException('Sesión caducada');
    $db=sfq_db();$jobs=[];
    foreach($tracks as $item) {
        if(!is_array($item)) continue;
        $url=yt_validate_url((string)($item['url']??''));
        $formatId = trim((string)($item['format_id'] ?? ''));
        if ($formatId !== '' && (strlen($formatId) > 100 || !preg_match('/^[a-zA-Z0-9._-]+$/D', $formatId))) throw new RuntimeException('Formato de origen inválido.');
        $filename=yt_safe_filename((string)($item['filename']??$item['title']??'Pista'),'audio');
        $payload=['url'=>$url,'filename'=>$filename,'format_id'=>$formatId];
        if($cookiePath!==null) {
            $cookieFile=sfq_dir().'/'.bin2hex(random_bytes(16)).'.cookies';
            if(!copy($cookiePath,$cookieFile)) throw new RuntimeException('No se pudieron guardar cookies para la cola');
            @chmod($cookieFile,0600);$payload['cookies']=$cookieFile;
        }
        try {$jobs[]=sfq_add($db,(string)$account['id'],'conversion','youtube',$albumId,$filename,$payload);}
        catch(Throwable $e){if(isset($payload['cookies']))@unlink($payload['cookies']);throw $e;}
    }
    yt_json(['success'=>true,'queued'=>true,'job_ids'=>$jobs,'imported'=>0,'results'=>[]],202);
} catch (Throwable $e) {
    sf_control_error('youtube.failed',$e);
    yt_json(['error' => $e->getMessage()], 400);
} finally {
    if ($cookiePath !== null && is_file($cookiePath)) @unlink($cookiePath);
}
