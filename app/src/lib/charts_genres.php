<?php
declare(strict_types=1);
/** Protected selection policy: classifications use published musical genres only. */
function sfc_genres():array {return ['pop'=>'Pop','latin_pop'=>'Pop latino / en español','rock'=>'Rock','alternative'=>'Alternativa / indie','electronic'=>'Electrónica','dance'=>'Dance','jazz'=>'Jazz','classical'=>'Clásica','country'=>'Country','folk'=>'Folk','rnb'=>'R&B','soul'=>'Soul / funk','blues'=>'Blues','metal'=>'Metal','reggae'=>'Reggae','tropical'=>'Salsa / tropical','cumbia'=>'Cumbia','christian'=>'Cristiana / gospel','hip_hop'=>'Rap / hip-hop','latin_urban'=>'Urbano latino / reggaetón / trap','regional_mexican'=>'Regional mexicano (incluye corridos)','latin_unspecified'=>'Latina sin subgénero (puede incluir regional)','soundtrack'=>'Bandas sonoras','children'=>'Infantil','world'=>'Músicas del mundo','other'=>'Otros géneros identificados'];}
function sfc_genre_tags(array $genres):array {
 $tags=[];foreach($genres as $genre){$g=sf_chart_fold((string)$genre);if($g===''||in_array($g,['music','musica'],true))continue;$found=[];
  foreach(['hip_hop'=>'hip hop|hiphop|rap','latin_urban'=>'reggaeton|urbano latino|latin urban|trap','regional_mexican'=>'regional mexican|musica mexicana|mexican music|corridos?|banda|norteno|rancher','latin_pop'=>'latin pop|pop latino|pop en espanol','alternative'=>'alternativ|indie','rock'=>'rock','electronic'=>'electro|electronica','dance'=>'dance','jazz'=>'jazz','classical'=>'clasica|classical','country'=>'country','folk'=>'folk','rnb'=>'r b|rhythm.*blues','soul'=>'soul|funk','blues'=>'blues','metal'=>'metal','reggae'=>'reggae','tropical'=>'salsa|tropical','cumbia'=>'cumbia','christian'=>'christian|cristian|gospel|worship','soundtrack'=>'soundtrack|banda sonora|peliculas|films|games','children'=>'infantil|children','world'=>'world|mundo'] as $key=>$pattern)if(preg_match('/\b(?:'.$pattern.')/u',$g))$found[]=$key;
  if(!in_array('latin_pop',$found,true)&&preg_match('/\bpop\b/u',$g))$found[]='pop';if(!$found&&preg_match('/\blatin[ao]?\b/u',$g))$found[]='latin_unspecified';if(!$found)$found[]='other';$tags=array_merge($tags,$found);
 }$tags=array_values(array_unique($tags));if(count($tags)>1)$tags=array_values(array_diff($tags,['latin_unspecified','other']));return $tags;
}
function sfc_select_items(array $snapshot,array $settings):array {
 $limit=$settings['tracks_per_chart'];$items=array_slice($snapshot['items']??[],0,$limit);
 // Spotify Charts and Billboard Global 200 publish official unfiltered editions.
 // Neither publishes per-track genres; the administrator explicitly chose this exception.
 if(in_array($snapshot['provider']??'',['spotify','billboard_global'],true)){foreach($items as &$item){unset($item['genre_review'],$item['genre_tags']);}unset($item);return $items;}
 if(empty($settings['genre_filter_enabled']))return $items;
 $allowed=$settings['allowed_genres'];$approved=[];$unknown=[];
 foreach($snapshot['items']??[] as $item){$tags=sfc_genre_tags((array)($item['genres']??[]));$item['genre_tags']=$tags;
  if(!$tags){$item['genre_review']=true;$unknown[]=$item;continue;}
  if(array_diff($tags,$allowed))continue;$approved[]=$item;
 }
 $items=array_slice($approved,0,$limit);if(count($items)<$limit)$items=array_merge($items,array_slice($unknown,0,$limit-count($items)));usort($items,static fn($a,$b)=>$a['rank']<=>$b['rank']);return $items;
}
