<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
// Isolated fixtures: never reads or writes production users/preferences.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE user_ui_preferences(user_id TEXT PRIMARY KEY,show_management_controls INTEGER NOT NULL CHECK(show_management_controls IN(0,1)),updated_at TEXT NOT NULL)');
$account=['id'=>'manager-a','role'=>'user','can_manage_albums'=>1];
function lab_auth_pdo(): PDO { return $GLOBALS['pdo']; }
function lab_auth_current_account(): ?array { return $GLOBALS['account']; }
function lab_auth_can_manage_albums(): bool { $a=lab_auth_current_account();return $a && ($a['role']==='admin'||$a['can_manage_albums']===1); }
function lab_h(string $s): string {return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
require_once __DIR__.'/../lib/management_view.php';
require_once __DIR__.'/../lib/track_menu.php';
check(lab_ui_can_manage_albums(),'Manager default enabled');
lab_user_save_management_controls('manager-a',false);
check(!lab_ui_can_manage_albums(),'Manager listener view');
check(lab_auth_can_manage_albums(),'Actual permission retained');
check(lab_user_show_management_controls('manager-b'),'Another account unaffected');
ob_start();sf_track_menu(['id'=>'track1'],'album');$menu=ob_get_clean();check(str_contains($menu,'data-queue-only hidden')&&str_contains($menu,'data-queue-add="track"')&&!str_contains($menu,'data-track-properties')&&!str_contains($menu,'data-track-delete'),'Listener menu contains only hidden queue action');
lab_user_save_management_controls('manager-a',true);
check(lab_ui_can_manage_albums(),'Can re-enable');
ob_start();sf_track_menu(['id'=>'track1'],'album');check(str_contains(ob_get_clean(),'data-track-properties'),'Menu returns');
try{lab_user_save_management_controls('manager-b',false);throw new LogicException('Cross-account write accepted');}catch(RuntimeException $e){}
$account=['id'=>'listener','role'=>'user','can_manage_albums'=>0];
check(!lab_ui_can_manage_albums(),'Ordinary user never gets controls');
try{lab_user_save_management_controls('listener',true);throw new LogicException('Listener write accepted');}catch(RuntimeException $e){}
$account=['id'=>'admin','role'=>'admin','can_manage_albums'=>0];
check(lab_ui_can_manage_albums(),'Admin default enabled');
lab_user_save_management_controls('admin',false);
check(!lab_ui_can_manage_albums() && lab_auth_can_manage_albums(),'Admin UI preference preserves authorization');
echo "PASS: defaults, persistence, isolation, menus, permission separation.\n";
