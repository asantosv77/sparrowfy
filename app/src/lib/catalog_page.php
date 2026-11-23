<?php
require_once __DIR__.'/loose_order.php';
function sf_catalog_pages(string $view,string $term):array{
 $p=sf_reuse_db();$p->sqliteCreateFunction('sf_contains',static fn($a,$b)=>(int)sf_search_match((string)$a,(string)$b),2);
 $rows=$p->query('SELECT id,title,artist,created_at,metadata_json FROM albums ORDER BY created_at DESC,id')->fetchAll(PDO::FETCH_ASSOC);
 $chartIds=[];$albums=[];foreach($rows as $r){$meta=json_decode($r['metadata_json']??'{}',true)?:[];if($view==='library'&&!empty($meta['music_chart'])){$chartIds[]=$r['id'];continue;}if(($meta['album_type']??'')==='online_links')throw new RuntimeException('Virtual catalog requires legacy view');$albums[]=['id'=>$r['id'],'title'=>$r['title'],'artist'=>$r['artist']];}
 require_once __DIR__.'/album_deletion.php';
 $hiddenIds=array_values(array_unique(array_merge(lab_auth_is_admin()?[]:sf_delreq_sql_hidden_ids(),$chartIds)));
 $hiddenWhere=$hiddenIds?' AND id NOT IN ('.implode(',',array_fill(0,count($hiddenIds),'?')).')':'';
 // Include reference appearances, omitting a reference already owned by the destination.
 $cte="WITH appearances AS (SELECT album_id,id AS track_id FROM tracks UNION SELECT r.album_id,r.track_id FROM sf_album_track_refs r JOIN tracks t ON t.id=r.track_id AND t.album_id=r.source_album_id JOIN albums a ON a.id=r.album_id) ";
 $from=' FROM appearances x JOIN tracks t ON t.id=x.track_id JOIN albums a ON a.id=x.album_id ';
 $root='fffffffffffffffe';$cache=[];
 $hydrate=function($id)use(&$cache){if(!isset($cache[$id])){$a=lab_load_album($id);if(!$a)throw new RuntimeException('Catalog index mismatch');$cache[$id]=sf_reuse_view($a);}return $cache[$id];};
 $page=function($sql,$args,$key,$map)use($p){
  $q=$p->prepare('SELECT COUNT(*) FROM ('.$sql.')');$q->execute($args);$total=(int)$q->fetchColumn();
  $size=(int)($_GET['per_page']??25);if(!in_array($size,[25,50,100],true))$size=25;$pages=max(1,(int)ceil($total/$size));$n=max(1,min($pages,(int)($_GET[$key]??1)));
  $q=$p->prepare($sql.' LIMIT '.$size.' OFFSET '.(($n-1)*$size));$q->execute($args);
  return ['items'=>array_map($map,$q->fetchAll(PDO::FETCH_ASSOC)),'total'=>$total,'size'=>$size,'page'=>$n,'pages'=>$pages,'key'=>$key];
 };
 $mapTrack=function($r)use($hydrate){$a=$hydrate($r['album_id']);foreach($a['tracks']??[] as $i=>$t)if($t['id']===$r['track_id'])return ['album'=>$a,'track'=>$t,'index'=>$i];throw new RuntimeException('Track index mismatch');};
 $albumSql='SELECT id FROM albums WHERE id<>?'.($hiddenIds?' AND id NOT IN ('.implode(',',array_fill(0,count($hiddenIds),'?')).')':'');$args=array_merge([$root],$hiddenIds);if($term!==''){$albumSql.=' AND (sf_contains(title,?) OR sf_contains(artist,?))';$args[]=$term;$args[]=$term;}
 $albumPage=$view==='tracks'?sf_page([],'albums_page'):$page($albumSql.' ORDER BY created_at DESC,id',$args,'albums_page',fn($r)=>$hydrate($r['id']));
 $hiddenTrack=$hiddenIds?'a.id NOT IN ('.implode(',',array_fill(0,count($hiddenIds),'?')).')':'';
 $where='';$args=[];
 if($term!==''){
 if($view==='tracks'){$where=" WHERE sf_contains(COALESCE(t.name,'')||' '||COALESCE(t.original_name,'')||' '||COALESCE(a.title,'')||' '||COALESCE(a.artist,'')||' '||COALESCE(json_extract(t.metadata_json,'$.artist'),'')||' '||COALESCE(json_extract(t.metadata_json,'$.title'),''),?)";$args=[$term];}
 else{$where=" WHERE (sf_contains(t.name,?) OR sf_contains(t.original_name,?) OR sf_contains(a.title,?) OR sf_contains(COALESCE(a.artist,'')||' '||COALESCE(json_extract(t.metadata_json,'$.artist'),''),?))";$args=array_fill(0,4,$term);}
 if($hiddenTrack){$where.=' AND '.$hiddenTrack;$args=array_merge($args,$hiddenIds);}
 }elseif($hiddenTrack){$where=' WHERE '.$hiddenTrack;$args=$hiddenIds;}
 $order=' ORDER BY a.created_at DESC,a.id,CASE WHEN t.album_id=x.album_id THEN 0 ELSE 1 END,CASE WHEN t.album_id=x.album_id THEN t.position ELSE NULL END,(SELECT created_at FROM sf_album_track_refs r WHERE r.album_id=x.album_id AND r.track_id=x.track_id),t.id';
 $trackPage=($view==='library'&&$term==='')?sf_page([],'tracks_page'):$page($cte.'SELECT x.album_id,x.track_id'.$from.$where.$order,$args,'tracks_page',$mapTrack);
 $loosePage=$view==='tracks'?sf_page([],'loose_page'):$page($cte.'SELECT x.album_id,x.track_id,t.imported_at AS loose_added'.$from.' WHERE x.album_id=?'.sf_loose_sql_order($p,sf_loose_order()),[$root],'loose_page',function($r)use($mapTrack){$v=$mapTrack($r);return $v['track']+['album_id'=>$r['album_id'],'album_title'=>'Pistas sin asignar a colección','index'=>$v['index'],'_loose_added'=>strtotime((string)($r['loose_added']??''))?:0];});
 $q=$p->prepare($cte.'SELECT COUNT(*) FROM appearances WHERE album_id<>?'.($hiddenIds?' AND album_id NOT IN ('.implode(',',array_fill(0,count($hiddenIds),'?')).')':''));$q->execute(array_merge([$root],$hiddenIds));$albumTrackCount=(int)$q->fetchColumn();
 $albums=array_values(array_filter($albums,fn($a)=>$a['id']!==$root));
 return compact('albums','albumPage','trackPage','loosePage','albumTrackCount');
}
