<?php
declare(strict_types=1);
// SQLite locates the canonical album; JSON remains the metadata authority.
function sf_track_lookup(string $id):?array{
 if(!preg_match('/^[a-f0-9]{16}$/D',$id))return null;
 $q=lab_auth_pdo()->prepare('SELECT album_id FROM tracks WHERE id=?');$q->execute([$id]);$aid=$q->fetchColumn();
 if($aid){$a=lab_load_album((string)$aid);foreach($a['tracks']??[] as $t)if(($t['id']??'')===$id)return ['track'=>$t,'album'=>$a];}
 // Virtual/legacy entries and stale index locations still resolve from JSON.
 foreach(lab_list_albums() as $a)foreach($a['tracks']??[] as $t)if(($t['id']??'')===$id)return ['track'=>$t,'album'=>$a];
 return null;
}
