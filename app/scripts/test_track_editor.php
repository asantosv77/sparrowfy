<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../lib/track_editor.php';
$old=['id'=>'abc','name'=>'Visible','title'=>'Title','artist'=>'Artist','album'=>'Album','genre'=>'Rock','year'=>'2000','artwork'=>'','hls'=>'hls_out/protected/master.m3u8','job_id'=>'job','duration'=>123];
$new=sf_editor_metadata(['name'=>'Nuevo nombre','title'=>'Título real','artwork'=>'https://is1-ssl.mzstatic.com/image/test.jpg'],$old);
if($new['id']!==$old['id']||$new['hls']!==$old['hls']||$new['job_id']!==$old['job_id']||$new['duration']!==123||$new['artist']!=='Artist'||$new['name']===$new['title'])throw new Exception('Protected audio or independent fields changed');
foreach([['name'=>''],['year'=>'abcd'],['genre'=>str_repeat('a',101)],['title'=>"bad\nvalue"],['artwork'=>'javascript:alert(1)'],['artwork'=>'https://mzstatic.com.attacker.test/x'],['artwork'=>'https://user@is1-ssl.mzstatic.com/x']] as $bad){
 try{sf_editor_metadata($bad,$old);throw new LogicException('Invalid metadata accepted');}catch(RuntimeException $e){}
}
if($old['name']!=='Visible')throw new Exception('Input mutated on validation');
echo "PASS: metadata validation, image URL restrictions, independent name/title and audio preserved\n";
