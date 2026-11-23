<?php
declare(strict_types=1);
function sf_album_name_key(string $name):string{
 $name=preg_replace('/\s+/u',' ',trim($name))??trim($name);
 return function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
}
function sf_album_find_named(string $title,bool $online=false):?array{
 $key=sf_album_name_key($title);$matches=[];
 foreach(lab_list_albums() as $a){if(($a['id']??'')==='fffffffffffffffe')continue;
  if((($a['album_type']??'')==='online_links')!==$online)continue;
  if(sf_album_name_key((string)($a['title']??''))===$key)$matches[]=$a;
 }
 usort($matches,static fn($a,$b)=>strcmp((string)($a['created_at']??''),(string)($b['created_at']??''))?:strcmp($a['id'],$b['id']));
 return $matches[0]??null;
}
