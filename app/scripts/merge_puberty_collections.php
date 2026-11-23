<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/lib.php';require_once dirname(__DIR__).'/lib/library_move.php';
$target='4701b9cadc667644';$sources=['2c58d8151bdb1110','41e66a1d746ad285'];
$backup=LAB_ROOT.'/backups/puberty-merge-'.gmdate('YmdHis');mkdir($backup,02770,true);
$db=lab_auth_pdo();$db->exec('VACUUM INTO '.$db->quote($backup.'/catalog-before.sqlite'));
foreach(array_merge([$target],$sources) as $id){$a=lab_load_album($id);if(!$a||$a['title']!=='Puberty 2')throw new RuntimeException('Album preflight failed');file_put_contents($backup.'/'.$id.'.json',json_encode($a,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));}
$expected=[];foreach(array_merge([$target],$sources) as $id){$a=lab_load_album($id);foreach($a['tracks'] as $t){if(isset($expected[$t['id']]))throw new RuntimeException('Duplicate id');$f=LAB_ROOT.'/'.$t['hls'];if(!is_file($f))throw new RuntimeException('Missing manifest');$expected[$t['id']]=hash_file('sha256',$f);}}
$q=$db->query("SELECT COUNT(*) FROM sf_processing_jobs WHERE state IN ('queued','running') AND album_id IN ('4701b9cadc667644','2c58d8151bdb1110','41e66a1d746ad285')");if($q->fetchColumn())throw new RuntimeException('Album busy');$q->closeCursor();
foreach($sources as $source){foreach(lab_load_album($source)['tracks'] as $t){sf_move_track($source,$target,$t['id']);echo 'Moved '.$t['id'].PHP_EOL;}}
$merged=lab_load_album($target);usort($merged['tracks'],static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));lab_save_album($merged);
if(count($merged['tracks'])!==count($expected))throw new RuntimeException('Count mismatch');
foreach($merged['tracks'] as $t)if(hash_file('sha256',LAB_ROOT.'/'.$t['hls'])!==$expected[$t['id']])throw new RuntimeException('Manifest checksum mismatch');
// Retire only empty collection metadata, retaining recovery copies and all audio.
$db->beginTransaction();try{foreach($sources as $id){
$q=$db->prepare('SELECT source_album_id,track_id,created_at FROM sf_album_track_refs WHERE album_id=?');$q->execute([$id]);$refs=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
foreach($refs as $ref){if(!isset($expected[$ref['track_id']]))$db->prepare('INSERT OR IGNORE INTO sf_album_track_refs(album_id,source_album_id,track_id,created_at) VALUES(?,?,?,?)')->execute([$target,$ref['source_album_id'],$ref['track_id'],$ref['created_at']]);}
$db->prepare('DELETE FROM sf_album_track_refs WHERE album_id=?')->execute([$id]);
if(lab_load_album($id)['tracks'])throw new RuntimeException('Source not empty');$q=$db->prepare('SELECT COUNT(*) FROM sf_album_track_refs WHERE album_id=? OR source_album_id=?');$q->execute([$id,$id]);if($q->fetchColumn())throw new RuntimeException('Source still referenced');$q->closeCursor();$db->prepare('DELETE FROM albums WHERE id=?')->execute([$id]);}$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
foreach($sources as $id)if(!rename(LAB_ALBUMS.'/'.$id,$backup.'/retired-'.$id))throw new RuntimeException('Metadata archive failed');
echo 'PASS: '.count($merged['tracks']).' tracks, IDs and manifest hashes preserved; backup='.basename($backup).PHP_EOL;
