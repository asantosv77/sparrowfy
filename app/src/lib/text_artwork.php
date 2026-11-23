<?php
function sf_art_clean(string $s):string{
 $s=preg_replace('/\.(mp3|flac|m4a|wav|ogg|opus|webm)$/i','',$s)??$s;
 $s=preg_replace('/[\[(][^\])]*(?:official|audio|lyric|video)[^\])]*[\])]/iu','',$s)??$s;
 return trim(preg_replace('/\s*[-;]\s*(?:animated music video|español)\s*$/iu','',$s)??$s);
}
function sf_art_norm(string $s):string{
 $s=mb_strtolower($s,'UTF-8');if(class_exists('Normalizer'))$s=Normalizer::normalize($s,Normalizer::FORM_D)?:$s;
 $s=preg_replace('/\p{Mn}/u','',$s)??$s;return preg_replace('/[^\p{L}\p{N}]/u','',$s)??$s;
}
function sf_art_pairs(array $t):array{
 $title=sf_art_clean((string)($t['title']??$t['name']??''));if($title==='')$title=sf_art_clean((string)($t['name']??''));$artist=trim((string)($t['artist']??''));$pairs=[];
 if($artist!==''&&!preg_match('/^(varios|various artists|unknown artist|artista desconocido)$/iu',$artist))$pairs[]=[$artist,$title];
 $name=sf_art_clean((string)($t['name']??''));$parts=preg_split('/\s+[-–—]\s+/u',$name,2);
 if(count($parts)===2){$pairs[]=[$parts[0],$parts[1]];$pairs[]=[$parts[1],$parts[0]];}
 return $pairs;
}
function sf_art_choose(array $track,array $pairs,array $results):?string{
 $matches=[];$identities=[];
 foreach($results as $c){$artist=sf_art_norm((string)($c['artistName']??''));$title=sf_art_norm(sf_art_clean((string)($c['trackName']??'')));$match=false;
 foreach($pairs as [$a,$t])if($artist===sf_art_norm($a)&&$title===sf_art_norm(sf_art_clean($t)))$match=true;
 if(!$match)continue;$url=(string)($c['artworkUrl100']??'');$parts=parse_url($url);if(($parts['scheme']??'')!=='https'||!preg_match('/(^|\.)mzstatic\.com$/i',(string)($parts['host']??''))||isset($parts['user'])||isset($parts['port']))continue;
 $identities[$artist.'|'.$title]=true;$delta=abs((float)($track['duration']??0)-(float)($c['trackTimeMillis']??0)/1000);$matches[]=['url'=>preg_replace('~/100x100[^/]*$~','/600x600bb.jpg',$url),'delta'=>$delta];
 }
 if(count($identities)!==1)return null;usort($matches,static fn($a,$b)=>$a['delta']<=>$b['delta']);return $matches[0]['url']??null;
}
