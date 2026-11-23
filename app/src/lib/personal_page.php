<?php
// SQL pagination for personal views; playback queues retain their complete lists.
function sf_personal_page(PDO $p,string $uid,string $kind):array{
 if(!in_array($kind,['favorites','recent'],true))throw new InvalidArgumentException('Vista inválida');
 $size=(int)($_GET['per_page']??25);if(!in_array($size,[25,50,100],true))$size=25;
 $from=' FROM tracks t JOIN albums a ON a.id=t.album_id ';
 $from.=$kind==='favorites'?'JOIN favorites f ON f.track_id=t.id WHERE f.user_id=?':'JOIN recent_tracks r ON r.track_id=t.id WHERE r.user_id=?';
 $q=$p->prepare('SELECT COUNT(*)'.$from);$q->execute([$uid]);$total=(int)$q->fetchColumn();
 if($kind==='recent')$total=min(100,$total);
 $pages=max(1,(int)ceil($total/$size));$page=max(1,min($pages,(int)($_GET['tracks_page']??1)));$offset=($page-1)*$size;
 $limit=min($size,max(0,$total-$offset));
 $order=$kind==='favorites'?'f.created_at DESC,t.name':'r.played_at DESC';
 $q=$p->prepare('SELECT t.id,t.album_id,t.name,t.position,t.duration,a.title AS album_title,a.artist'.$from.' ORDER BY '.$order.' LIMIT '.$limit.' OFFSET '.$offset);$q->execute([$uid]);
 return ['items'=>$q->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'size'=>$size,'page'=>$page,'pages'=>$pages,'key'=>'tracks_page'];
}
