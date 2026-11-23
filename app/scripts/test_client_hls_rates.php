<?php
if(PHP_SAPI!=='cli')exit;
require_once dirname(__DIR__).'/lib/client_processing.php';
function check($v,$label){if(!$v)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
$cfg=['quality_ladder_mode'=>'profiles'];$caps=[64,128,192,256];
check(sfwc_pick_rates(320,$cfg,$caps)===[64,192,256],'320k uses complete configured ladder');
check(sfwc_pick_rates(910,$cfg,$caps,'flac')===[64,192,256],'FLAC uses lossless ladder');
check(sfwc_pick_rates(128,$cfg,$caps)===[64,128],'128k does not invent higher quality');
check(sfwc_pick_rates(192,$cfg,$caps)===[64,128,192],'192k preserves its own profile');
$rejected=false;try{sfwc_pick_rates(320,$cfg,[96,128,160,192]);}catch(RuntimeException $e){$rejected=true;}check($rejected,'incomplete browser capability falls back instead of publishing 96/128');
$custom=$cfg+['quality_source_profiles'=>['320'=>[64,128,256]]];check(sfwc_pick_rates(320,$custom,$caps)===[64,128,256],'administrator profile remains authoritative');
$id=bin2hex(random_bytes(16));$dir=sfwc_root().'/'.$id;mkdir($dir,0700);sfwc_save($dir,['album_id'=>'fixture','source_kbps'=>320,'duration'=>2,'rates'=>[96,128],'chunks'=>[]]);
try{$rejected=false;try{sfwc_convert(['client_session'=>$id],['id'=>'fixture'],$cfg);}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'escalera');}check($rejected,'server rejects stale partial local manifest before creating output');}finally{sfwc_remove($dir);}
