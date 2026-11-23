<?php
declare(strict_types=1);
function sf_loose_order_options():array{return ['recent'=>'Fecha: más recientes','oldest'=>'Fecha: más antiguas','name'=>'Nombre: A–Z','name_desc'=>'Nombre: Z–A','bitrate'=>'Bitrate: mayor a menor','bitrate_asc'=>'Bitrate: menor a mayor','no_artwork'=>'Sin carátula primero'];}
function sf_loose_order():string{$v=(string)($_GET['loose_sort']??'recent');return array_key_exists($v,sf_loose_order_options())?$v:'recent';}
function sf_loose_name_key(string $value):string{return strtr(mb_strtolower($value,'UTF-8'),['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u']);}
function sf_loose_stamp(array $t):int{return (int)($t['_loose_added']??(strtotime((string)($t['added_at']??$t['created_at']??''))?:0));}
function sf_loose_has_artwork(array $t):bool{return trim((string)($t['artwork']??''))!=='';}
function sf_loose_prepare(array $rows):array{
 if(!$rows)return []; $dates=[];try{foreach(lab_auth_pdo()->query("SELECT id,imported_at FROM tracks WHERE album_id='fffffffffffffffe'") as $r)$dates[$r['id']]=strtotime((string)$r['imported_at'])?:0;}catch(Throwable $e){error_log('Loose dates: '.$e->getMessage());}
 foreach($rows as &$t)$t['_loose_added']=$dates[$t['id']]??sf_loose_stamp($t);unset($t);return $rows;
}
function sf_loose_sort(array $rows,string $order):array{
 $rows=sf_loose_prepare($rows);usort($rows,static function($a,$b)use($order){
 $date=sf_loose_stamp($b)<=>sf_loose_stamp($a);$tie=$date?:((int)($b['index']??0)<=>(int)($a['index']??0))?:strcmp((string)$a['id'],(string)$b['id']);
 $name=strnatcmp(sf_loose_name_key((string)($a['name']??'')),sf_loose_name_key((string)($b['name']??'')));
 return match($order){'oldest'=>-$tie,'name'=>$name?:$tie,'name_desc'=>-$name?:$tie,'bitrate'=>((int)($b['source_kbps']??0)<=>(int)($a['source_kbps']??0))?:$tie,'bitrate_asc'=>((int)($a['source_kbps']??0)<=>(int)($b['source_kbps']??0))?:$tie,'no_artwork'=>(sf_loose_has_artwork($a)<=>sf_loose_has_artwork($b))?:$tie,default=>$tie};
 });return $rows;
}
function sf_loose_sql_order(PDO $db,string $order):string{
 $db->sqliteCreateCollation('SF_LOOSE_NAME',static fn($a,$b)=>strnatcmp(sf_loose_name_key($a),sf_loose_name_key($b)));
 $recent="COALESCE(t.imported_at,'') DESC,t.position DESC,t.id ASC";
 return ' ORDER BY '.match($order){'oldest'=>"COALESCE(t.imported_at,'') ASC,t.position ASC,t.id DESC",'name'=>'t.name COLLATE SF_LOOSE_NAME ASC,'.$recent,'name_desc'=>'t.name COLLATE SF_LOOSE_NAME DESC,'.$recent,'bitrate'=>'COALESCE(t.source_kbps,0) DESC,'.$recent,'bitrate_asc'=>'COALESCE(t.source_kbps,0) ASC,'.$recent,'no_artwork'=>"CASE WHEN TRIM(COALESCE(json_extract(t.metadata_json,'$.artwork'),''))='' THEN 0 ELSE 1 END ASC,".$recent,default=>$recent};
}
