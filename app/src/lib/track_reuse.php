<?php
declare(strict_types=1);
require_once __DIR__.'/album_track_order.php';
function sf_reuse_db():PDO {
 static $ready=null;$ready??=new WeakMap();
 $p=lab_auth_pdo();if(isset($ready[$p]))return $p;
 $p->exec('CREATE TABLE IF NOT EXISTS sf_album_track_refs(album_id TEXT NOT NULL,source_album_id TEXT NOT NULL,track_id TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(album_id,track_id))');
 $p->exec('CREATE INDEX IF NOT EXISTS sf_refs_source ON sf_album_track_refs(source_album_id,track_id)');$ready[$p]=true;return $p;
}
function sf_reuse_lock(){
 $f=fopen(LAB_DATA.'/.track-reuse.lock','c');if(!$f||!flock($f,LOCK_EX))throw new RuntimeException('No se pudo bloquear el catálogo');return $f;
}
function sf_reuse_view(array $album):array {
 $p=sf_reuse_db();$q=$p->prepare('SELECT * FROM sf_album_track_refs WHERE album_id=? ORDER BY created_at,track_id');$q->execute([$album['id']]);
 $seen=array_column($album['tracks']??[],'id');$sourceAlbums=[];
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){if(in_array($r['track_id'],$seen,true))continue;$source=$sourceAlbums[$r['source_album_id']]??=lab_load_album($r['source_album_id']);
 foreach($source['tracks']??[] as $t)if(($t['id']??'')===$r['track_id']){$t['_ref_source']=$r['source_album_id'];$album['tracks'][]=$t;$seen[]=$t['id'];break;}}
 return sf_album_order_view($album);
}
function sf_reuse_attach(string $source,string $tid,string $target):bool{
 foreach([$source,$tid,$target] as $id)if(!preg_match('/^[a-f0-9]{16}$/D',$id))throw new RuntimeException('Identificador inválido');
 $lock=sf_reuse_lock();try{
 $a=lab_load_album($source);$b=lab_load_album($target);if(!$a||!$b)throw new RuntimeException('Colección inexistente');
 if($target==='fffffffffffffffe')throw new RuntimeException('Elige una colección de destino');
 $track=null;foreach($a['tracks']??[] as $t)if(($t['id']??'')===$tid)$track=$t;
 if(!$track)throw new RuntimeException('Pista original no encontrada');
 if((($a['album_type']??'')==='online_links')!== (($b['album_type']??'')==='online_links'))throw new RuntimeException('Elige una colección del mismo tipo: audio local o enlaces externos');
 foreach($b['tracks']??[] as $t)if(($t['id']??'')===$tid)return false;
 $insert=sf_reuse_db()->prepare('INSERT OR IGNORE INTO sf_album_track_refs VALUES(?,?,?,?)');$insert->execute([$target,$source,$tid,gmdate('c')]);return $insert->rowCount()===1;
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function sf_reuse_guard(string $source,string $tid=''):void{
 $q=sf_reuse_db()->prepare('SELECT COUNT(*) FROM sf_album_track_refs WHERE source_album_id=?'.($tid!==''?' AND track_id=?':''));$q->execute($tid!==''?[$source,$tid]:[$source]);
 if($q->fetchColumn())throw new RuntimeException('Esta pista o colección se reutiliza en otros álbumes. Retira esas referencias antes de borrar o mover el original; el audio compartido se conserva.');
}
function sf_reuse_fold(string $value): string {
    $value = mb_strtolower($value, 'UTF-8');
    if (class_exists('Normalizer')) $value = Normalizer::normalize($value, Normalizer::FORM_D) ?: $value;
    $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
    return trim(preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value);
}
function sf_reuse_words(string $value): array {
    static $stop = null;
    $stop ??= array_fill_keys(['official','oficial','video','videoclip','audio','lyrics','lyric','letra','hd','hq','mv','en','vivo','live','remastered','remaster','the','and','with','feat','ft','con','de','del','la','el','los','las','y','o','u','a','in','on','or'], true);
    $parts = preg_split('/\s+/u', sf_reuse_fold($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($parts as $part) {
        if (mb_strlen($part) > 1 && !ctype_digit($part) && !isset($stop[$part])) $out[$part] = true;
    }
    return array_keys($out);
}
function sf_reuse_split_name(string $name): array {
    $name = preg_replace('/\.(?:mp3|m4a|webm|opus|flac|wav|ogg|wma|aac)$/iu', '', trim($name)) ?? $name;
    if (preg_match('/\s+[-–—]\s+/u', $name, $m, PREG_OFFSET_CAPTURE) === 1) {
        return ['artist' => substr($name, 0, (int) $m[0][1]), 'title' => substr($name, (int) $m[0][1] + strlen($m[0][0]))];
    }
    return ['artist' => '', 'title' => $name];
}
function sf_reuse_overlap(array $needles, array $haystack): array {
    if (!$needles || !$haystack) return [0, 0.0];
    $hits = array_values(array_intersect($needles, $haystack));
    return [count($hits), count($hits) / count($needles)];
}
function sf_reuse_search(string $term, float $duration = 0):array{
    $term=trim($term);
    if(mb_strlen($term)<2||mb_strlen($term)>160)return [];
    $parsed=sf_reuse_split_name($term);
    $queryTitle=sf_reuse_words($parsed['title']);
    $queryArtist=sf_reuse_words($parsed['artist']);
    if(!$queryTitle)return [];
    $queryAll=array_values(array_unique(array_merge($queryTitle,$queryArtist)));
    $duration=$duration>0&&$duration<10800?$duration:0.0;
    $p=sf_reuse_db();
    $cols=[];
    foreach($p->query('PRAGMA table_info(tracks)') as $col)$cols[]=(string)($col['name']??'');
    if(!$cols)return [];
    $hasDuration=in_array('duration',$cols,true);
    $rows=$p->query('SELECT id,album_id,name,original_name'.($hasDuration?',duration':',NULL AS duration').' FROM tracks')->fetchAll(PDO::FETCH_ASSOC);
    // Virtual YouTube links live in JSON and are not normalized into tracks.
    foreach(lab_list_albums() as $a)if(($a['album_type']??'')==='online_links')foreach($a['tracks']??[] as $t)$rows[]=['id'=>(string)($t['id']??''),'album_id'=>$a['id'],'name'=>(string)($t['name']??''),'original_name'=>'','duration'=>(float)($t['duration']??0)];
    $matches=[];
    foreach($rows as $row){
        if(!preg_match('/^[a-f0-9]{16}$/i',(string)($row['id']??'')))continue;
        $name=(string)($row['name']??'');
        if($name==='')continue;
        $candidate=sf_reuse_split_name($name);
        $candTitleWords=sf_reuse_words($candidate['title']);
        $candAllWords=sf_reuse_words($name.' '.(string)($row['original_name']??''));
        if(!$candTitleWords)$candTitleWords=$candAllWords;
        // El título es obligatorio: coincidir solo con el artista nunca basta.
        [$titleHits,$titleRecall]=sf_reuse_overlap($queryTitle,$candAllWords);
        if($titleHits===0)continue;
        [, $candRecall]=sf_reuse_overlap($candTitleWords,$queryAll);
        $similarity=min($titleRecall,$candRecall);
        if($titleRecall>=0.999&&$candRecall>=0.999)$similarity=1.0;
        $artistHits=0;$artistRecall=0.0;
        if($queryArtist)[$artistHits,$artistRecall]=sf_reuse_overlap($queryArtist,$candAllWords);
        $candidateDuration=(float)($row['duration']??0);
        $delta=$duration>0&&$candidateDuration>0?abs($duration-$candidateDuration):null;
        // Una diferencia grande de duración indica otra grabación (versión en vivo, cover, etc.).
        if($delta!==null&&$delta>45)continue;
        $accept=$similarity>=0.66
            || ($similarity>=0.5&&$titleHits>=2&&($artistRecall>0||($delta!==null&&$delta<=8)))
            || ($similarity>=0.999);
        if(!$accept)continue;
        $durationFactor=$delta===null?0.5:max(0.0,1.0-$delta/30.0);
        $score=(int)round(100*(0.64*$similarity+0.21*$artistRecall+0.15*$durationFactor));
        $reason=[];
        if($similarity>=0.999)$reason[]='título idéntico';elseif($similarity>=0.8)$reason[]='título casi idéntico';else $reason[]='título similar';
        if($artistRecall>=0.5)$reason[]='artista coincide';
        if($delta!==null)$reason[]=$delta<=3?'duración casi idéntica':'duración ±'.(int)round($delta).' s';
        $matches[(string)$row['id'].'@'.$row['album_id']]=$row+['_score'=>$score,'_reason'=>implode(', ',$reason),'_similarity'=>$similarity];
    }
    uasort($matches,static fn($a,$b)=>($b['_score']<=>$a['_score'])?:strcmp((string)$a['name'],(string)$b['name']));
    $out=[];foreach(array_slice($matches,0,30,true) as $r){$a=lab_load_album($r['album_id']);if(!$a)continue;$i=null;foreach($a['tracks']??[] as $n=>$t)if(($t['id']??'')===$r['id']){$i=$n;break;}if($i===null)continue;
        $locations=[['id'=>$a['id'],'title'=>$a['id']==='fffffffffffffffe'?'Pistas sin asignar a colección':$a['title']]];
        $q=$p->prepare('SELECT album_id FROM sf_album_track_refs WHERE source_album_id=? AND track_id=?');$q->execute([$a['id'],$r['id']]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $aid){$b=lab_load_album($aid);if($b)$locations[]=['id'=>$aid,'title'=>$b['title']];}
        $out[]=['id'=>$r['id'],'album_id'=>$r['album_id'],'name'=>$r['name'],'duration'=>$duration>0||(float)($r['duration']??0)>0?(float)($r['duration']??0):null,'match_score'=>(int)$r['_score'],'match_reason'=>(string)$r['_reason'],'locations'=>$locations,'play_url'=>'player.php?album='.rawurlencode($a['id']).'&i='.$i];
    }
    return $out;
}

// Caller holds the shared catalog lock. Remove membership, preserving track identity.
function sf_reuse_remove_locked(string $albumId,string $tid,?array &$batch=null):void{
 $p=$batch['db']??sf_reuse_db();$a=$batch['albums'][$albumId]??lab_load_album($albumId);if(!$a)throw new RuntimeException('Colección inexistente');
 $original=null;foreach($a['tracks']??[] as $t)if(($t['id']??'')===$tid)$original=$t;
 if(!$original){$p->prepare('DELETE FROM sf_album_track_refs WHERE album_id=? AND track_id=?')->execute([$albumId,$tid]);return;}
 $q=$p->prepare('SELECT album_id FROM sf_album_track_refs WHERE source_album_id=? AND track_id=? ORDER BY created_at,album_id');$q->execute([$albumId,$tid]);$target=null;
 foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)if(isset($batch['albums'][$id])||lab_load_album($id)){$target=$id;break;}
 $online=($a['album_type']??'')==='online_links';
 if(!$target && !$online){require_once __DIR__.'/library_root.php';$target=sf_ensure_loose()['id'];}
 if($target===$albumId)throw new RuntimeException('La pista ya está sin asignar a colección');
 require_once __DIR__.'/library_move.php';
 if($target){sf_move_track_impl($albumId,$target,$tid,$batch);return;}
 if($batch!==null){$a['tracks']=array_values(array_filter($a['tracks']??[],fn($t)=>($t['id']??'')!==$tid));hml_delete_atomic_save_album($a);lab_sqlite_sync_album($a,$p);$batch['albums'][$albumId]=$a;return;}
 // External URL with no remaining references: no media file or SQLite track to delete.
 $f=fopen(LAB_ALBUMS.'/'.$albumId.'/.processing-append.lock','c');if(!$f||!flock($f,LOCK_EX|LOCK_NB))throw new RuntimeException('Colección ocupada');
 try{$a=lab_load_album($albumId);$a['tracks']=array_values(array_filter($a['tracks']??[],fn($t)=>($t['id']??'')!==$tid));hml_delete_atomic_save_album($a);require_once __DIR__.'/sqlite_catalog_sync.php';lab_sqlite_sync_album($a);}finally{flock($f,LOCK_UN);fclose($f);}
}
function sf_reuse_preserve_before_delete(string $albumId,string $tid='',?array &$batch=null):void{
 $p=$batch['db']??sf_reuse_db();$a=$batch['albums'][$albumId]??lab_load_album($albumId);foreach($a['tracks']??[] as $t){$id=$t['id'];if($tid!==''&&$tid!==$id)continue;
 $q=$p->prepare('SELECT COUNT(*) FROM sf_album_track_refs WHERE source_album_id=? AND track_id=?');$q->execute([$albumId,$id]);$used=(bool)$q->fetchColumn();
 foreach(['playlist_tracks','favorites'] as $table){$q=$p->prepare("SELECT COUNT(*) FROM $table WHERE track_id=?");$q->execute([$id]);$used=$used||(bool)$q->fetchColumn();}
 if($used)sf_reuse_remove_locked($albumId,$id,$batch);
 }
}


// Expand a complete catalog with one reference query and no repeated JSON reads.
function sf_reuse_views(array $albums):array{
 if(!$albums)return [];
 $sources=[];foreach($albums as $a)$sources[$a['id']]=array_column($a['tracks']??[],null,'id');
 $refs=[];foreach(sf_reuse_db()->query('SELECT album_id,source_album_id,track_id FROM sf_album_track_refs ORDER BY created_at,track_id')->fetchAll(PDO::FETCH_ASSOC) as $r)$refs[$r['album_id']][]=$r;
 foreach($albums as &$a){$seen=array_fill_keys(array_column($a['tracks']??[],'id'),true);
 foreach($refs[$a['id']]??[] as $r){$id=$r['track_id'];if(isset($seen[$id]))continue;$t=$sources[$r['source_album_id']][$id]??null;if(!$t)continue;$t['_ref_source']=$r['source_album_id'];$a['tracks'][]=$t;$seen[$id]=true;}
 $a=sf_album_order_view($a);}
 unset($a);return $albums;
}
