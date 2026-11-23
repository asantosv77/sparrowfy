<?php
declare(strict_types=1);
require '/var/www/html/audio/lib/spotify_charts.php';

function spotify_fixture_entry(int $rank,?int $previous,string $id,string $title,string $artist):array {
 return ['chartEntryData'=>['currentRank'=>$rank,'previousRank'=>$previous,'peakRank'=>$rank,'appearancesOnChart'=>3,'entryStatus'=>$previous===null?'NEW_ENTRY':'MOVEMENT'],'trackMetadata'=>['trackName'=>$title,'trackUri'=>'spotify:track:'.$id,'displayImageUri'=>'https://i.scdn.co/image/abc','artists'=>[['name'=>$artist,'spotifyUri'=>'spotify:artist:abc']]]];
}
function spotify_fixture_payload(array $entries,string $alias='REGIONAL_MX_WEEKLY',string $recurrence='WEEKLY'):array {
 return ['displayChart'=>['date'=>'2026-11-12','chartMetadata'=>['alias'=>$alias,'entityType'=>'TRACK','dimensions'=>['recurrence'=>$recurrence]]],'entries'=>$entries];
}
function spotify_expect(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
function spotify_expect_throw(callable $fn,string $why):void {try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('No se rechazó: '.$why);}

$idA=str_repeat('A',22);$idB=str_repeat('b',22);
$payload=spotify_fixture_payload([
 spotify_fixture_entry(1,null,$idA,'Canción Uno','Artista Uno'),
 spotify_fixture_entry(2,1,$idB,'Canción Dos','Artista Dos'),
]);
$chart=sfc_spotify_normalize('mx',$payload);
spotify_expect($chart['provider']==='spotify','provider');spotify_expect($chart['market']==='mx','market');
spotify_expect($chart['period_kind']==='weekly','period_kind');
spotify_expect($chart['issue_date']==='2026-11-12','issue_date');
spotify_expect($chart['period_start']==='2026-11-06','period_start');
spotify_expect($chart['source_url']==='https://charts.spotify.com/charts/view/regional-mx-weekly/2026-11-12','source_url');
spotify_expect(count($chart['items'])===2,'items count');
spotify_expect($chart['items'][0]['rank']===1,'rank 1');
spotify_expect($chart['items'][0]['official_previous_rank']===null,'new entry');
spotify_expect($chart['items'][1]['official_previous_rank']===1,'previous rank');
spotify_expect($chart['items'][0]['source_url']==='https://open.spotify.com/track/'.$idA,'track url');
spotify_expect($chart['items'][0]['artwork']==='https://i.scdn.co/image/abc','artwork');
$legacy=sfc_spotify_normalize('mx',spotify_fixture_payload([spotify_fixture_entry(1,null,$idA,'A','B')],'regional-mx-weekly'));
spotify_expect(count($legacy['items'])===1,'alias en minusculas');
$negative=sfc_spotify_normalize('mx',spotify_fixture_payload([spotify_fixture_entry(1,-1,$idA,'A','B')]));
spotify_expect($negative['items'][0]['official_previous_rank']===null,'previousRank -1');

spotify_expect_throw(fn()=>sfc_spotify_normalize('mx',spotify_fixture_payload([spotify_fixture_entry(1,null,$idA,'A','B')],'regional-mx-weekly','DAILY')),'daily recurrence');
spotify_expect_throw(fn()=>sfc_spotify_normalize('us',spotify_fixture_payload([spotify_fixture_entry(1,null,$idA,'A','B')])),'alias/país distinto');
spotify_expect_throw(fn()=>sfc_spotify_normalize('mx',spotify_fixture_payload([spotify_fixture_entry(1,null,$idA,'A','B'),spotify_fixture_entry(1,2,$idB,'C','D')])),'posición duplicada');
spotify_expect_throw(fn()=>sfc_spotify_normalize('mx',spotify_fixture_payload([spotify_fixture_entry(1,null,$idA,'A','B'),spotify_fixture_entry(2,1,$idA,'C','D')])),'canción duplicada');
$bad=spotify_fixture_entry(1,null,$idA,'A','B');$bad['trackMetadata']['trackUri']='https://example.com/track/x';
spotify_expect_throw(fn()=>sfc_spotify_normalize('mx',spotify_fixture_payload([$bad])),'enlace no Spotify');

echo "SPOTIFY_NORMALIZE_OK\n";
