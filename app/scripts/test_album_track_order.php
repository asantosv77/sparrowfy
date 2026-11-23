<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$fixture=sys_get_temp_dir().'/sf-album-order-'.bin2hex(random_bytes(8));mkdir($fixture,0700);define('LAB_DATA',$fixture);define('LAB_ALBUMS',$fixture.'/albums');mkdir(LAB_ALBUMS,0700);
function lab_auth_pdo():PDO{$db=new PDO('sqlite:'.LAB_DATA.'/catalog.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec('PRAGMA busy_timeout=1500');return $db;}
function lab_load_album(string $id):?array{$db=lab_auth_pdo();$q=$db->prepare('SELECT metadata_json FROM albums WHERE id=?');$q->execute([$id]);$s=$q->fetchColumn();if($s===false)return null;$a=json_decode($s,true)+['id'=>$id];$q=$db->prepare('SELECT id,name,hls_path AS hls FROM tracks WHERE album_id=? ORDER BY position,id');$q->execute([$id]);$a['tracks']=$q->fetchAll();return $a;}
function check($v,$label){if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function rejects(callable $f,$label){try{$f();}catch(Throwable $e){check(true,$label);return;}throw new RuntimeException('Not rejected: '.$label);}
require dirname(__DIR__).'/lib/track_reuse.php';
$db=lab_auth_pdo();$db->exec('CREATE TABLE albums(id TEXT PRIMARY KEY,metadata_json TEXT,updated_at TEXT);CREATE TABLE tracks(id TEXT PRIMARY KEY,album_id TEXT,position INTEGER,name TEXT,hls_path TEXT)');sf_reuse_db();
$aid=str_repeat('a',16);$sid=str_repeat('b',16);$one=str_repeat('1',16);$two=str_repeat('2',16);$ref=str_repeat('3',16);
try{
 foreach([$aid,$sid] as $id){mkdir(LAB_ALBUMS.'/'.$id,0700);$db->prepare('INSERT INTO albums VALUES(?,?,?)')->execute([$id,json_encode(['title'=>'fixture','artist'=>'preserved']),null]);file_put_contents(LAB_ALBUMS.'/'.$id.'/album.json',json_encode(['id'=>$id,'title'=>'fixture','tracks'=>[]]));}
 foreach([[$one,$aid,0],[$two,$aid,1],[$ref,$sid,0]] as [$id,$a,$i])$db->prepare('INSERT INTO tracks VALUES(?,?,?,?,?)')->execute([$id,$a,$i,'song-'.$id,'hls/'.$id.'/master.m3u8']);
 $db->prepare('INSERT INTO sf_album_track_refs VALUES(?,?,?,?)')->execute([$aid,$sid,$ref,gmdate('c')]);
 $sourceBefore=lab_load_album($sid);$order=[$ref,$two,$one];$before=[$one,$two,$ref];
 check(sf_album_order_save($aid,$order,$before)===$order,'saves full album order including reference');
 check(array_column(sf_reuse_view(lab_load_album($aid))['tracks'],'id')===$order,'single-album reader uses persisted visible order');
 $views=sf_reuse_views([lab_load_album($aid),lab_load_album($sid)]);check(array_column($views[0]['tracks'],'id')===$order,'library reader uses same order');
 check(lab_load_album($sid)===$sourceBefore,'source album of reused track is unchanged');
 $json=json_decode(file_get_contents(LAB_ALBUMS.'/'.$aid.'/album.json'),true);check(array_column($json['tracks'],'id')===[$two,$one]&&$json['track_order']===$order,'JSON keeps canonical tracks separate from references');
 check(lab_load_album($aid)['artist']==='preserved','album metadata preserved');
 rejects(fn()=>sf_album_order_save($aid,$before,$before),'stale order rejected');
 rejects(fn()=>sf_album_order_save($aid,[$ref,$two,$two],$order),'duplicate IDs rejected');
 rejects(fn()=>sf_album_order_save($aid,[$ref,$one],$order),'missing track rejected');
 rejects(fn()=>sf_album_order_save($aid,[$ref,$one,str_repeat('4',16)],$order),'foreign track rejected');
 check(array_column(sf_reuse_view(lab_load_album($aid))['tracks'],'id')===$order,'invalid requests never change order');
 $new=str_repeat('5',16);$db->prepare('INSERT INTO tracks VALUES(?,?,?,?,?)')->execute([$new,$aid,2,'new song','new/master.m3u8']);check(array_column(sf_reuse_view(lab_load_album($aid))['tracks'],'id')===[$ref,$two,$one,$new],'newly imported tracks append after saved order');
 echo "All tests passed in temporary SQLite fixture; production catalog untouched.\n";
}finally{$db=null;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($fixture);}
