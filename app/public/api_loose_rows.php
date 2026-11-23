<?php
declare(strict_types=1);
require_once __DIR__.'/lib/processing_queue.php';
require_once __DIR__.'/lib/library_root.php';
require_once __DIR__.'/lib/track_menu.php';require_once __DIR__.'/lib/loose_order.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(!lab_auth_current_account()){http_response_code(401);echo json_encode(['error'=>'Sesión caducada']);exit;}
if(!lab_auth_can_manage_albums()){http_response_code(403);echo json_encode(['error'=>'No autorizado']);exit;}
$account=lab_auth_current_account();$ids=array_values(array_unique(array_filter(explode(',',(string)($_GET['jobs']??'')),static fn($id)=>preg_match('/^[a-f0-9]{32}$/D',$id))));$ids=array_slice($ids,0,100);
$wanted=[];$q=sfq_db()->prepare("SELECT user_id,result_json FROM sf_processing_jobs WHERE id=? AND state='done' AND album_id=?");
foreach($ids as $id){$q->execute([$id,SF_LOOSE_ID]);$job=$q->fetch(PDO::FETCH_ASSOC);if(!$job||($job['user_id']!==$account['id']&&$account['role']!=='admin'))continue;$result=json_decode($job['result_json']??'',true);$tid=$result['track']['id']??null;if(is_string($tid))$wanted[$tid]=true;}
$root=lab_load_album(SF_LOOSE_ID)??['tracks'=>[]];$looseRows=[];
foreach($root['tracks']??[] as $index=>$track){if(!isset($wanted[$track['id']??'']))continue;$track['album_id']=SF_LOOSE_ID;$track['index']=$index;$looseRows[]=$track;}
$canManageAlbums=lab_ui_can_manage_albums();$albums=[];
if($canManageAlbums)foreach(lab_list_albums() as $album)if(!sf_is_loose($album))$albums[]=$album;
$looseRows=sf_loose_prepare($looseRows);ob_start();require __DIR__.'/loose_track_rows.php';$html=ob_get_clean();
echo json_encode(['html'=>$html,'total'=>count($root['tracks']??[]),'ids'=>array_column($looseRows,'id')],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
