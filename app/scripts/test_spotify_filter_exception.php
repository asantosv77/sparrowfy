<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require '/var/www/html/audio/lib/charts_genres.php';
require '/var/www/html/audio/lib/external_charts_audio.php';

function sfce(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}

$settings=['tracks_per_chart'=>3,'genre_filter_enabled'=>true,'allowed_genres'=>['pop']];

$spotify=['provider'=>'spotify','items'=>[
 ['rank'=>1,'title'=>'Uno','artist'=>'A','genres'=>[]],
 ['rank'=>3,'title'=>'Dos','artist'=>'B','genres'=>[]],
 ['rank'=>7,'title'=>'Tres','artist'=>'C','genres'=>[]],
 ['rank'=>9,'title'=>'Cuatro','artist'=>'D','genres'=>[]],
]];
$out=sfc_select_items($spotify,$settings);
sfce(count($out)===3,'Spotify respeta tracks_per_chart');
sfce(array_column($out,'rank')===[1,3,7],'Spotify conserva posiciones oficiales con huecos');
foreach($out as $t)sfce(empty($t['genre_review'])&&empty($t['genre_tags']),'Spotify no se marca para revision de genero');

$apple=['provider'=>'apple','items'=>[
 ['rank'=>1,'title'=>'Rock','artist'=>'A','genres'=>['Rock']],
 ['rank'=>2,'title'=>'Sin genero','artist'=>'B','genres'=>[]],
 ['rank'=>3,'title'=>'Pop','artist'=>'C','genres'=>['Pop']],
]];
$out=sfc_select_items($apple,$settings);
$titles=array_column($out,'title');
sfce(!in_array('Rock',$titles,true),'Apple sigue excluyendo generos no permitidos');
sfce(in_array('Pop',$titles,true),'Apple sigue conservando generos permitidos');
sfce(in_array('Sin genero',$titles,true),'Apple sigue dejando desconocidos para revision');
foreach($out as $t)if($t['title']==='Sin genero')sfce(!empty($t['genre_review']),'Apple desconocido conserva genre_review');

$legacy=['items'=>[['rank'=>1,'genres'=>[]]]];
$out=sfc_select_items($legacy,$settings);
sfce(!empty($out[0]['genre_review']),'Snapshots sin provider mantienen el filtro');

echo "SPOTIFY_FILTER_EXCEPTION_OK\n";
