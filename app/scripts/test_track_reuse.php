<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/lib/track_reuse.php';
$dir=dirname(__DIR__).'/data/qa-reuse-'.bin2hex(random_bytes(4));mkdir($dir);define('LAB_DATA',$dir);
$p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function lab_auth_pdo(){return $GLOBALS['p'];}function lab_load_album($id){return $GLOBALS['albums'][$id]??null;}function lab_list_albums(){return array_values($GLOBALS['albums']);}
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}function rejects($fn){try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException('Expected rejection');}
$a=str_repeat('a',16);$b=str_repeat('b',16);$tid=str_repeat('1',16);$c=str_repeat('c',16);
$albums=[$a=>['id'=>$a,'title'=>'Origen','tracks'=>[['id'=>$tid,'name'=>'Árbol Azul','hls'=>'hls_out/original/master.m3u8']]],$b=>['id'=>$b,'title'=>'Destino','tracks'=>[]],$c=>['id'=>$c,'title'=>'Online','album_type'=>'online_links','tracks'=>[]]];
try{
$p->exec('CREATE TABLE tracks(id TEXT PRIMARY KEY,album_id TEXT,name TEXT,original_name TEXT)');$p->prepare('INSERT INTO tracks VALUES(?,?,?,?)')->execute([$tid,$a,'Árbol Azul','arbol.mp3']);
check(sf_reuse_attach($a,$tid,$a)===false,'Original already exists');check(sf_reuse_attach($a,$tid,$b)===true,'First association');check(sf_reuse_attach($a,$tid,$b)===false,'Reference already exists');check($p->query('SELECT COUNT(*) FROM sf_album_track_refs')->fetchColumn()==1,'Repeated link');
$v=sf_reuse_view($albums[$b]);check(count($v['tracks'])===1&&$v['tracks'][0]['hls']===$albums[$a]['tracks'][0]['hls'],'Shared path');check(!$albums[$b]['tracks'],'JSON untouched');
$albums[$a]['tracks'][0]['name']='Nuevo título';check(sf_reuse_view($albums[$b])['tracks'][0]['name']==='Nuevo título','Live metadata');
$r=sf_reuse_search('ÁRBOL azul.mp3');check(count($r)===1&&count($r[0]['locations'])===2,'Search locations');check(sf_reuse_search("% ' OR 1=1 --")===[],'Safe query');
rejects(fn()=>sf_reuse_guard($a,$tid));rejects(fn()=>sf_reuse_guard($a));rejects(fn()=>sf_reuse_attach($a,$tid,$c));rejects(fn()=>sf_reuse_attach($a,str_repeat('2',16),$b));
$p->prepare('DELETE FROM sf_album_track_refs WHERE album_id=?')->execute([$b]);sf_reuse_guard($a,$tid);check(count(sf_reuse_view($albums[$b])['tracks'])===0,'Detach');check(count($albums[$a]['tracks'])===1,'Source survives');
echo "PASS: idempotent references, same audio, metadata propagation, search/locations, guards, detach and source preservation\n";
}finally{@unlink($dir.'/.track-reuse.lock');rmdir($dir);}

