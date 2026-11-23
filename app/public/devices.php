<?php
require __DIR__.'/lib.php';require_once __DIR__.'/lib/user_preferences.php';require_once __DIR__.'/lib/mobile_sessions.php';header('Cache-Control: no-store');
$me=lab_auth_current_account();$sfTheme=lab_user_theme((string)($me['id']??''));$admin=lab_auth_is_admin();$adminView=$admin&&($_GET['admin']??'')==='1';$uid=(string)$me['id'];
$selected=(string)($_GET['user_id']??$uid);
if(!$admin&&$selected!==$uid){http_response_code(403);exit('Sin permiso para esta cuenta.');}
$p=sf_remember_db();$notice='';$error='';$embed=!empty($_GET['embed']);
function device_time($time){return (new DateTimeImmutable('@'.(int)$time))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('d/m/Y H:i');}
function device_mobile_time(?string $iso):string{$t=is_string($iso)&&$iso!==''?strtotime($iso):false;return $t===false?'—':device_time($t);}
function device_label($ua){
 $os=preg_match('/iPhone|iPad|iPod/i',$ua)?'iPhone/iPad':(stripos($ua,'Android')!==false?'Android':(stripos($ua,'Windows')!==false?'Windows':(stripos($ua,'Macintosh')!==false?'Mac':(stripos($ua,'Linux')!==false?'Linux':'Equipo'))));
 $browser=stripos($ua,'FxiOS')!==false||stripos($ua,'Firefox')!==false?'Firefox':(stripos($ua,'Edg')!==false?'Edge':(stripos($ua,'Chrome')!==false||stripos($ua,'CriOS')!==false?'Chrome / compatible':(stripos($ua,'Safari')!==false?'Safari / app web':'Cliente')));return $os.' · '.$browser;
}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 try{
  if(!lab_auth_verify_csrf((string)($_POST['csrf_token']??'')))throw new RuntimeException('La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.');
  $target=(string)($_POST['user_id']??'');$action=(string)($_POST['action']??'');
  if(!in_array($action,['revoke','revoke_all','revoke_mobile','revoke_mobile_all'],true))throw new RuntimeException('Acción inválida');
  if(($_POST['confirm']??'')!=='1')throw new RuntimeException('Confirma el cierre de acceso');
  if(!$admin&&!hash_equals($uid,$target))throw new RuntimeException('Sin permiso para esta cuenta');
  if($action==='revoke_mobile'||$action==='revoke_mobile_all'){
   if($action==='revoke_mobile'){
    $sid=(string)($_POST['session_id']??'');
    if(!preg_match('/^[a-f0-9]{32}$/D',$sid))throw new RuntimeException('Sesión móvil inválida');
    $q=$p->prepare('UPDATE sessions SET revoked_at=? WHERE id=? AND user_id=? AND revoked_at IS NULL');
    $q->execute([gmdate('c'),$sid,$target]);
    if($q->rowCount()===0)throw new RuntimeException('La sesión móvil ya estaba cerrada o no existe.');
    $notice='Sesión móvil cerrada. La aplicación tendrá que iniciar sesión de nuevo.';
   }else{
    $n=sf_mobile_sessions_revoke_user($p,$target);
    $notice=$n>0?'Se cerraron '.$n.' sesiones móviles.':'No había sesiones móviles activas.';
   }
  }else{
   $device=$action==='revoke'?(string)($_POST['device_id']??''):null;
   if($device!==null&&!preg_match('/^[a-f0-9]{32}$/D',$device))throw new RuntimeException('Acceso inválido');
   sf_device_revoke($p,$uid,$admin,$target,$device);
   if($action==='revoke_all'){
    $n=sf_mobile_sessions_revoke_user($p,$target);
    $notice='Se cerraron todos los accesos de la cuenta'.($n>0?' y '.$n.' sesiones móviles':'').'.';
   }else $notice='Acceso cerrado y token recordado revocado.';
   if($target===$uid&&($device===null||$device===($_SESSION['device_id']??''))){
    sf_remember_cookie('',time()-3600);$_SESSION=[];session_destroy();echo '<!doctype html><meta charset="utf-8"><p>Acceso cerrado. <a target="_top" href="login.php">Iniciar sesión</a></p><script>window.top.location.href="login.php";</script>';exit;
   }
  }
 }catch(Throwable $e){$error=$e->getMessage();}
}
$users=$adminView?$p->query('SELECT id,username FROM users ORDER BY username COLLATE NOCASE')->fetchAll():[];
$q=$p->prepare('SELECT username,password_hash,is_active FROM users WHERE id=?');$q->execute([$selected]);$target=$q->fetch();if(!$target){http_response_code(404);exit('Cuenta no encontrada');}
$epoch=sf_device_epoch($p,$selected);$stamp=hash('sha256',$target['password_hash']);
$q=$p->prepare('SELECT d.*,EXISTS(SELECT 1 FROM sf_device_remember m JOIN sf_remember_tokens t ON t.selector=m.selector WHERE m.device_id=d.id AND t.expires_at>? AND t.created_at>?) AS remembered FROM sf_devices d WHERE d.user_id=? ORDER BY d.last_seen DESC LIMIT 200');$q->execute([time(),time()-sf_remember_days()*86400,$selected]);$rows=$q->fetchAll();
sf_mobile_sessions_schema($p);
$mq=$p->prepare('SELECT id,device_label,created_at,expires_at,last_used_at,revoked_at FROM sessions WHERE user_id=? ORDER BY COALESCE(last_used_at,created_at) DESC LIMIT 200');$mq->execute([$selected]);$mobile=$mq->fetchAll();
$mobileNow=gmdate('c');$mobileActive=0;foreach($mobile as $m)if($m['revoked_at']===null&&(string)$m['expires_at']>$mobileNow)$mobileActive++;
$recent=0;foreach($rows as $r)if($r['revoked_at']===null&&(int)$r['epoch']===$epoch&&hash_equals($r['credential_stamp'],$stamp)&&(int)$r['last_seen']>=time()-300)$recent++;
$overview=[];
if($adminView){
 $p->sqliteCreateFunction('sf_device_stamp',static fn($v)=>hash('sha256',(string)$v),1);
 $q=$p->prepare('SELECT u.id,u.username,COUNT(d.id) AS recent FROM users u LEFT JOIN sf_device_epochs e ON e.user_id=u.id LEFT JOIN sf_devices d ON d.user_id=u.id AND d.revoked_at IS NULL AND d.epoch=COALESCE(e.epoch,0) AND d.credential_stamp=sf_device_stamp(u.password_hash) AND d.last_seen>=? AND u.is_active=1 GROUP BY u.id,u.username ORDER BY recent DESC,u.username');$q->execute([time()-300]);$overview=$q->fetchAll();
}
$csrf=lab_h(lab_auth_csrf_token());$back=$adminView?'admin_users.php':'change_password.php';if($embed)$back.='?embed=1';
?><!doctype html><html lang="es" data-theme="<?=lab_h($sfTheme)?>" data-theme-source="server"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dispositivos y sesiones · Sparrow-Fy</title><link rel="stylesheet" href="assets/lab.css?v=theme-sweep1"><style>.devices{max-width:1050px;margin:auto}.device-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;padding:18px 0;border-bottom:1px solid var(--border,#34525b)}.device-row small{display:block;line-height:1.7;overflow-wrap:anywhere}.device-status{display:inline-block;padding:3px 9px;border-radius:12px;background:#10b98122}.device-note{color:var(--muted,#aac1c8);line-height:1.5}.device-row form{align-self:center}@media(max-width:600px){.device-row{grid-template-columns:1fr}.device-row form{justify-self:start}}</style><meta name="sf-session-account" content="<?= htmlspecialchars((string)(lab_auth_current_account()['id']??''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><script src="assets/session-sync.js?v=auth-poll3" defer></script>
<script defer src=assets/sf-language.js></script></head><body><main class="lab-main devices"><section class="card"><a class="btn" href="<?=lab_h($back)?>">Cerrar</a><h1><?=$adminView?'Accesos de usuarios':'Mis dispositivos y sesiones'?></h1>
<?php if($notice):?><p role="status"><?=lab_h($notice)?></p><?php endif;?><?php if($error):?><p role="alert"><?=lab_h($error)?></p><?php endif;?>
<?php if($adminView):?><form method="get"><input type="hidden" name="admin" value="1"><?php if($embed):?><input type="hidden" name="embed" value="1"><?php endif;?><label>Cuenta <select name="user_id"><?php foreach($users as $u):?><option value="<?=lab_h((string)$u['id'])?>" <?=$selected===(string)$u['id']?'selected':''?>><?=lab_h($u['username'])?></option><?php endforeach;?></select></label><button class="btn" type="submit">Ver accesos</button></form><?php endif;?>
<?php if($adminView):?><details><summary>Resumen de actividad por cuenta</summary><table style="width:100%;margin:12px 0"><thead><tr><th>Cuenta</th><th>Accesos recientes</th><th></th></tr></thead><tbody><?php foreach($overview as $u):?><tr><td><?=lab_h($u['username'])?></td><td><?=(int)$u['recent']?> <?php if((int)$u['recent']>1):?>· Revisar<?php endif;?></td><td><a class="btn" href="devices.php?admin=1&amp;user_id=<?=rawurlencode((string)$u['id'])?><?=$embed?'&amp;embed=1':''?>">Ver</a></td></tr><?php endforeach;?></tbody></table></details><?php endif;?>
<h2><?=lab_h($target['username'])?></h2><p><?=$recent?> accesos web con actividad en los últimos cinco minutos · <?=$mobileActive?> sesiones móviles activas.</p><p class="device-note">Cada registro representa un acceso del navegador o aplicación; no identifica de forma infalible un aparato físico. Varios accesos recientes son indicios para revisar, no prueban que se comparta la cuenta. La IP observada puede corresponder al proxy y cambiar con la red móvil. La actividad se actualiza como máximo una vez por minuto. El cierre se aplica a las siguientes solicitudes; el audio que ya estaba en búfer puede continuar unos momentos.</p>
<form method="post" onsubmit="return confirm('¿Cerrar todos los accesos de esta cuenta, incluida su sesión actual y las sesiones móviles? Podrá entrar nuevamente con su contraseña.');"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><input type="hidden" name="user_id" value="<?=lab_h($selected)?>"><input type="hidden" name="action" value="revoke_all"><button class="btn" name="confirm" value="1">Cerrar todos los accesos de esta cuenta</button></form>
<?php foreach($rows as $r):$current=$selected===$uid&&$r['id']===($_SESSION['device_id']??'');$valid=(int)$target['is_active']===1&&$r['revoked_at']===null&&(int)$r['epoch']===$epoch&&hash_equals($r['credential_stamp'],$stamp);?>
<article class="device-row"><div><strong><?=lab_h(device_label($r['user_agent']))?></strong> <?php if($current):?><span class="device-status">Este acceso</span><?php endif;?><small><?=$valid?'Acceso habilitado':'Acceso cerrado o invalidado'?> · <?=$valid&&$r['remembered']?'Sesión persistente':'Sin sesión persistente activa'?></small><small>Primer registro: <?=device_time($r['created_at'])?> · Última actividad: <?=device_time($r['last_seen'])?></small><small>IP observada: <?=lab_h($r['last_ip']?:'No disponible')?></small><details><summary>Detalles del cliente</summary><small><?=lab_h($r['user_agent'])?></small></details></div>
<?php if($valid):?><form method="post" onsubmit="return confirm('¿Cerrar este acceso y revocar su token recordado?');"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><input type="hidden" name="user_id" value="<?=lab_h($selected)?>"><input type="hidden" name="device_id" value="<?=lab_h($r['id'])?>"><input type="hidden" name="action" value="revoke"><button class="btn" name="confirm" value="1"><?=$current?'Cerrar este acceso':'Revocar acceso'?></button></form><?php endif;?></article>
<?php endforeach;if(!$rows):?><p>No hay accesos registrados todavía. Se registrarán cuando el usuario vuelva a utilizar la aplicación.</p><?php endif;?><p class="device-note">Revocar no cambia la contraseña. Si alguien la conoce, podrá iniciar una sesión nueva; cambia la contraseña si no reconoces un acceso. Se muestran hasta 200 registros recientes.</p>
<h2>Sesiones móviles (API)</h2>
<p class="device-note">Tokens Bearer emitidos por la API móvil. Cerrar una sesión invalida ese token en la siguiente petición; no cambia tu contraseña. Los manifiestos y segmentos HLS de esa aplicación dejan de responder con ese token.</p>
<?php if($mobileActive>0):?><form method="post" onsubmit="return confirm('¿Cerrar todas las sesiones móviles de esta cuenta? Las aplicaciones tendrán que iniciar sesión de nuevo.');"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><input type="hidden" name="user_id" value="<?=lab_h($selected)?>"><input type="hidden" name="action" value="revoke_mobile_all"><button class="btn" name="confirm" value="1">Cerrar todas las sesiones móviles</button></form><?php endif;?>
<?php foreach($mobile as $m):$mactive=$m['revoked_at']===null&&(string)$m['expires_at']>$mobileNow;$mlabel=trim((string)$m['device_label']);$mlabel=$mlabel!==''?$mlabel:'Aplicación móvil';?>
<article class="device-row"><div><strong><?=lab_h($mlabel)?></strong> <span class="device-status"><?=$mactive?'Activa':($m['revoked_at']!==null?'Cerrada':'Expirada')?></span><small>Creada: <?=device_mobile_time((string)$m['created_at'])?> · Último uso: <?=device_mobile_time($m['last_used_at']!==null?(string)$m['last_used_at']:null)?></small><small>Expira: <?=device_mobile_time((string)$m['expires_at'])?></small></div>
<?php if($mactive):?><form method="post" onsubmit="return confirm('¿Cerrar esta sesión móvil? La aplicación tendrá que iniciar sesión de nuevo.');"><input type="hidden" name="csrf_token" value="<?=$csrf?>"><input type="hidden" name="user_id" value="<?=lab_h($selected)?>"><input type="hidden" name="session_id" value="<?=lab_h((string)$m['id'])?>"><input type="hidden" name="action" value="revoke_mobile"><button class="btn" name="confirm" value="1">Cerrar sesión móvil</button></form><?php endif;?></article>
<?php endforeach;if(!$mobile):?><p>No hay sesiones móviles registradas todavía. Se crearán al iniciar sesión desde la aplicación o la API.</p><?php endif;?></section></main></body></html>
