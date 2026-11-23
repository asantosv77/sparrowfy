<?php
require_once dirname(__DIR__).'/lib/external_services.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);echo "PASS $why\n";}
$id='12345678-1234-1234-1234-123456789abc';
$r=sfa_search('musicbrainz','ABBA','Gold','',static fn()=>['releases'=>[['id'=>$id,'title'=>'Gold','artist-credit'=>[['name'=>'ABBA']],'date'=>'1992','track-count'=>2]]]);check(count($r)===1&&$r[0]['artist']==='ABBA','MusicBrainz search parser');
$r=sfa_release('musicbrainz',$id,static fn($url)=>str_contains($url,'coverartarchive')?[]:['title'=>'Gold','artist-credit'=>[['name'=>'ABBA']],'media'=>[['position'=>2,'tracks'=>[['position'=>1,'title'=>'B','length'=>100000]]],['position'=>1,'tracks'=>[['position'=>1,'title'=>'A','length'=>120000]]]]]);check(array_column($r['tracks'],'title')===['A','B'],'Disc and track order preserved');
$r=sfa_release('itunes','123',static fn()=>['results'=>[['wrapperType'=>'collection','collectionName'=>'Gold','artistName'=>'ABBA'],['kind'=>'song','trackName'=>'Dancing Queen','trackTimeMillis'=>230000,'trackNumber'=>1]]]);check($r['tracks'][0]['duration']===230.0,'iTunes duration and track parser');
$r=sfa_rank(['title'=>'Dancing Queen','artist'=>'ABBA','duration'=>230],[['id'=>'abcdefghijk','title'=>'ABBA - Dancing Queen Lyrics','channel'=>'ABBA','duration'=>230],['id'=>'lmnopqrstuv','title'=>'ABBA Dancing Queen karaoke lyrics','channel'=>'ABBA','duration'=>230],['id'=>'12345678901','title'=>'Dancing Queen lyrics','channel'=>'Another artist','duration'=>230]]);check($r[0]['id']==='abcdefghijk'&&$r[0]['confident']&&!$r[1]['confident']&&!$r[2]['confident'],'Lyrics bonus does not override wrong artist or karaoke');
try{sfa_release('musicbrainz','../../secret',static fn()=>[]);throw new LogicException('Unsafe ID accepted');}catch(InvalidArgumentException $e){check(true,'Unsafe release ID rejected');}
try{sfa_http('https://127.0.0.1/');throw new LogicException('Unsafe host accepted');}catch(RuntimeException $e){check(true,'Private/arbitrary hosts rejected before network');}
echo "Album discovery fixtures passed without remote calls.\n";
