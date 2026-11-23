<?php
declare(strict_types=1);

/**
 * Sparrow-Fy / HLS Music Lab
 * Gestión de trabajos de recorte y fade.
 *
 * Diagnóstico temporal:
 * consultar docker logs para las marcas [SF_AUDIO_EDIT].
 */
function sf_edit_debug(string $step): void
{
    static $start = null;

    if ($start === null) {
        $start = microtime(true);
    }

    $elapsed = round((microtime(true) - $start) * 1000, 1);

    error_log(sprintf(
        '[SF_AUDIO_EDIT] pid=%d elapsed_ms=%.1f %s',
        getmypid(),
        $elapsed,
        $step
    ));
}

sf_edit_debug('01 - Inicio de petición');

require __DIR__ . '/lib/audio_jobs.php';

sf_edit_debug('02 - Librerías cargadas');

lab_auth_require_album_manager();

sf_edit_debug('03 - Permisos verificados');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    sf_edit_debug('04 - Consultando cuenta');

    $account = lab_auth_current_account();

    if (!$account) {
        throw new RuntimeException('Sesión no disponible');
    }

    sf_edit_debug('05 - Cuenta consultada');

    $db = sf_jobs_db();
    $uid = (string) $account['id'];

    sf_edit_debug('06 - Base de datos preparada');

    $action = (string) (
        $_POST['action']
        ?? $_GET['action']
        ?? 'list'
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        sf_edit_debug('07 - Validando origen POST');

        lab_require_same_origin_post();

        sf_edit_debug('08 - Origen POST validado');
    }

    /*
     * La autenticación y los permisos ya fueron verificados.
     * Liberamos el bloqueo del archivo de sesión.
     */
    if (session_status() === PHP_SESSION_ACTIVE) {
        sf_edit_debug('09 - Liberando sesión');

        session_write_close();

        sf_edit_debug('10 - Sesión liberada');
    } else {
        sf_edit_debug('10 - No hay sesión activa');
    }

    /*
     * ENQUEUE: agregar trabajo de recorte/fade.
     */
    if (
        $action === 'enqueue'
        && $_SERVER['REQUEST_METHOD'] === 'POST'
    ) {
        sf_edit_debug('11 - Iniciando enqueue');

        $albumId = (string) ($_POST['album_id'] ?? '');
        $trackId = (string) ($_POST['track_id'] ?? '');

        if (
            !preg_match('/^[a-f0-9]{16}$/', $albumId)
            || !preg_match('/^[a-f0-9]{16}$/', $trackId)
        ) {
            throw new RuntimeException('Identificador inválido');
        }

        sf_edit_debug('12 - Identificadores validados');

        $album = lab_load_album($albumId);

        sf_edit_debug('13 - Lectura de álbum terminada');

        if (!$album) {
            throw new RuntimeException('Álbum no encontrado');
        }

        $track = null;

        foreach ($album['tracks'] ?? [] as $t) {
            if (($t['id'] ?? '') === $trackId) {
                $track = $t;
                break;
            }
        }

        if (!$track || empty($track['job_id'])) {
            throw new RuntimeException('Pista HLS no disponible');
        }

        sf_edit_debug('14 - Pista localizada');

        $params = [];

        foreach (
            ['trim_start', 'trim_end', 'fade_in', 'fade_out']
            as $key
        ) {
            $value = (string) ($_POST[$key] ?? '0');

            if (!is_numeric($value)) {
                throw new RuntimeException(
                    'Valor inválido: ' . $key
                );
            }

            $number = (float) $value;

            if (
                !is_finite($number)
                || $number < 0
                || $number > 10800
            ) {
                throw new RuntimeException(
                    'Valor fuera de rango: ' . $key
                );
            }

            $params[$key] = $number;
        }

        if (array_sum($params) <= 0) {
            throw new RuntimeException(
                'Indica al menos un recorte o fundido'
            );
        }

        // Validación real antes de crear el trabajo en la cola.
        sf_audio_edit_source_check($track);

        sf_edit_debug('15 - Parámetros validados');

        $id = bin2hex(random_bytes(16));
        $now = gmdate('c');

        try {
            sf_edit_debug('16 - Preparando INSERT');

            $q = $db->prepare(
                'INSERT INTO audio_edit_jobs (
                    id,
                    user_id,
                    album_id,
                    track_id,
                    track_name,
                    parameters,
                    state,
                    stage,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            sf_edit_debug('17 - Ejecutando INSERT');

            $q->execute([
                $id,
                $uid,
                $albumId,
                $trackId,
                (string) ($track['name'] ?? ''),
                json_encode($params),
                'queued',
                'En espera de procesamiento',
                $now,
                $now
            ]);

            sf_edit_debug('18 - INSERT completado');

        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                throw new RuntimeException(
                    'Esta pista ya tiene una edición pendiente'
                );
            }

            throw $e;
        }

        sf_edit_debug('19 - Enviando respuesta enqueue');

        echo json_encode([
            'success' => true,
            'id' => $id,
            'state' => 'queued'
        ]);

        exit;
    }

    /*
     * ACK: marcar notificación como recibida.
     */
    if (
        $action === 'ack'
        && $_SERVER['REQUEST_METHOD'] === 'POST'
    ) {
        sf_edit_debug('20 - Iniciando ACK');

        $id = (string) ($_POST['id'] ?? '');

        $q = $db->prepare(
            'UPDATE audio_edit_jobs
             SET notified_at = ?
             WHERE id = ?
               AND user_id = ?
               AND state IN ("done", "failed")'
        );

        $q->execute([
            gmdate('c'),
            $id,
            $uid
        ]);

        sf_edit_debug('21 - ACK completado');

        echo json_encode([
            'success' => true
        ]);

        exit;
    }

    /*
     * STATUS: consultar un trabajo.
     */
    if ($action === 'status') {
        sf_edit_debug('22 - Consultando STATUS');

        $q = $db->prepare(
            'SELECT *
             FROM audio_edit_jobs
             WHERE id = ?
               AND user_id = ?'
        );

        $q->execute([
            (string) ($_GET['id'] ?? ''),
            $uid
        ]);

        $r = $q->fetch();

        if (!$r) {
            http_response_code(404);

            echo json_encode([
                'error' => 'Trabajo no encontrado'
            ]);

            exit;
        }

        sf_edit_debug('23 - STATUS completado');

        echo json_encode([
            'success' => true,
            'job' => sf_jobs_public($r)
        ]);

        exit;
    }

    /*
     * LIST: consultar trabajos del usuario.
     */
    if ($action === 'list') {
        sf_edit_debug('24 - Consultando LIST');

        $q = $db->prepare(
            'SELECT *
             FROM audio_edit_jobs
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 30'
        );

        $q->execute([$uid]);

        $jobs = array_map(
            'sf_jobs_public',
            $q->fetchAll()
        );

        sf_edit_debug('25 - LIST completado');

        echo json_encode([
            'success' => true,
            'jobs' => $jobs
        ]);

        exit;
    }

    throw new RuntimeException('Acción no válida');

} catch (Throwable $e) {
    sf_edit_debug(
        'ERROR - '
        . get_class($e)
        . ': '
        . $e->getMessage()
    );

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

