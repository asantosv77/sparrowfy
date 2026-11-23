<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$account = lab_auth_current_account();
if (!$account) { http_response_code(401); echo json_encode(['error'=>'No autenticado']); exit; }
$allowed = ['dark-teal','dark-blue','dark-purple','light','light-sand','solarized','monokai','deep-black','ruby-blue'];
try {
    $pdo = lab_auth_pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_theme_preferences (user_id TEXT PRIMARY KEY, theme TEXT NOT NULL, updated_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        lab_require_same_origin_post();
        $theme = (string)($_POST['theme'] ?? '');
        if (!in_array($theme, $allowed, true)) throw new InvalidArgumentException('Tema no válido');
        $q=$pdo->prepare('INSERT INTO user_theme_preferences(user_id,theme,updated_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET theme=excluded.theme,updated_at=excluded.updated_at');
        $q->execute([(string)$account['id'],$theme,gmdate('c')]);
        echo json_encode(['success'=>true,'theme'=>$theme]);
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $q=$pdo->prepare('SELECT theme FROM user_theme_preferences WHERE user_id=?');
        $q->execute([(string)$account['id']]);
        $theme=$q->fetchColumn();
        echo json_encode(['success'=>true,'theme'=>in_array($theme,$allowed,true)?$theme:'dark-teal']);
    } else { http_response_code(405); echo json_encode(['error'=>'Método no permitido']); }
} catch (InvalidArgumentException $e) {http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}
catch (Throwable $e) {error_log('Theme preference: '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'No se pudo guardar o leer el tema']);}
