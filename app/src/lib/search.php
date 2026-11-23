<?php
declare(strict_types=1);
function sf_search_normalize(string $s): string {
 $s=mb_strtolower(mb_substr($s,0,2000,'UTF-8'),'UTF-8');
 $s=strtr($s,['á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','ñ'=>'n','ç'=>'c']);
 $s=preg_replace('/[\p{M}]/u','',$s)??$s;
 return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$s)??$s);
}
function sf_search_match(string $text,string $query): bool {
 static $cache=[];$key=$text."\0".$query;if(isset($cache[$key]))return $cache[$key];
 $q=sf_search_normalize(mb_substr($query,0,160));$t=sf_search_normalize($text);
 if($q==='')return true;
 $words=explode(' ',$t);$ok=true;
 foreach(explode(' ',$q) as $term){
  if(str_contains($t,$term))continue;
  $len=mb_strlen($term);$allowed=$len>=8?2:($len>=4?1:0);$found=false;
  if($allowed)foreach($words as $word){if(abs(mb_strlen($word)-$len)<=$allowed&&levenshtein($term,$word)<=$allowed){$found=true;break;}}
  if(!$found){$ok=false;break;}
 }
 if(count($cache)>3000)$cache=[];return $cache[$key]=$ok;
}
