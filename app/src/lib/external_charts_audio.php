<?php
declare(strict_types=1);
/** Pure matching rules for automated imports. Queue/transport remain protected elsewhere. */
function sf_chart_fold(string $value):string {
 $value=mb_strtolower($value,'UTF-8');$value=strtr($value,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','’'=>"'",'&'=>' y ']);$value=preg_replace('/\p{Mn}+/u','',$value)??$value;
 return trim(preg_replace('/[^\pL\pN]+/u',' ',$value)??$value);
}
function sf_chart_artist(string $value):string { $value=sf_chart_fold($value);return trim(preg_replace('/\b(feat|ft|featuring|and|y)\b/u','',$value)??$value); }
function sf_chart_identity(array $track):string {
 $isrc=(string)($track['isrc']??'');if(preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/D',$isrc))return 'isrc:'.$isrc;
 return hash('sha256',sf_chart_fold(sf_chart_artist((string)$track['artist'])).'|'.sf_chart_fold((string)$track['title']));
}
function sf_chart_candidate(array $expected,array $candidate,string $kind):array {
 $want=sf_chart_fold((string)$expected['title']);$artist=sf_chart_fold(sf_chart_artist((string)$expected['artist']));$raw=(string)($candidate['title']??'');$title=sf_chart_fold($raw);$who=sf_chart_fold(sf_chart_artist((string)($candidate['artist']??'')));$channel=sf_chart_fold((string)($candidate['channel']??''));$reason=[];
 $versionFlags=['cover','karaoke','instrumental','nightcore','slowed','sped','remix','live','vivo','remastered','remaster','acoustic','acustico'];
 foreach($versionFlags as $flag)if(preg_match('/\b'.$flag.'\b/u',$title)&&!preg_match('/\b'.$flag.'\b/u',$want))return ['confident'=>false,'score'=>0,'reason'=>'Versión distinta: '.$flag];
 if($kind==='youtube'){
  $rawArtist=sf_chart_fold((string)$expected['artist']);if(str_starts_with($title,$rawArtist.' '))$title=trim(substr($title,strlen($rawArtist)));elseif(str_starts_with($title,$artist.' '))$title=trim(substr($title,strlen($artist)));
  $title=trim(preg_replace('/\b(official|oficial|audio|video|music|visualizer|visualiser|lyrics?|letra|hd|hq|4k|1080p)\b/u','',$title)??$title);$title=trim(preg_replace('/\s+/u',' ',$title)??$title);
  $tokens=explode(' ',$artist);$hay=sf_chart_fold(sf_chart_artist($raw.' '.$channel));$hits=0;foreach($tokens as $token)if($token!==''&&preg_match('/(?:^| )'.preg_quote($token,'/').'(?: |$)/u',$hay))$hits++;
  $artistOK=$tokens&&$hits===count($tokens);
 }else{$artistOK=$who===$artist;}
 $isrc=(string)($expected['isrc']??'');$sameISRC=$isrc!==''&&$isrc===(string)($candidate['isrc']??'');
 if(!$sameISRC&&($title!==$want||!$artistOK))return ['confident'=>false,'score'=>0,'reason'=>'No coincide exactamente el título y artista'];
 $duration=(float)($expected['duration']??0);$actual=(float)($candidate['duration']??0);
 if($duration>0&&$actual>0&&abs($duration-$actual)>min(10,max(5,$duration*.03)))return ['confident'=>false,'score'=>0,'reason'=>'Duración incompatible'];
 $score=$sameISRC?100:92;if($duration>0&&$actual>0)$score+=3;if($kind==='youtube'&&preg_match('/\b(topic|vevo|official|oficial)\b/iu',$raw.' '.$channel))$score+=4;
 return ['confident'=>true,'score'=>min(100,$score),'reason'=>$sameISRC?'ISRC coincidente':'Título y artista coincidentes'.($duration>0&&$actual>0?' · duración compatible':' · duración del ranking no disponible')];
}
function sf_chart_pick(array $track,array $items,string $kind):?array {
 $ranked=[];foreach(array_slice($items,0,5) as $item){$match=sf_chart_candidate($track,$item,$kind);if($match['confident'])$ranked[]=$item+['match'=>$match];}
 usort($ranked,static fn($a,$b)=>$b['match']['score']<=>$a['match']['score']);
 // Identical confidence for distinct ambiguous versions is not resolved by search order.
 if(count($ranked)>1&&$ranked[0]['match']['score']===$ranked[1]['match']['score']&&sf_chart_fold((string)($ranked[0]['album']??''))!==sf_chart_fold((string)($ranked[1]['album']??''))){$a=(float)($ranked[0]['duration']??0);$b=(float)($ranked[1]['duration']??0);if(!$a||!$b||abs($a-$b)>3)return null;}
 return $ranked[0]??null;
}
