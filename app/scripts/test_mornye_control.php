<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$dir=sys_get_temp_dir().'/sfmn-test-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$src=file_get_contents(dirname(__DIR__).'/lib/mornye_control.php');
$src=str_replace("return dirname(__DIR__).'/data/mornye-control';",'return '.var_export($dir,true).';',$src);
file_put_contents($dir.'/control.php',$src);require $dir.'/control.php';
try{
 sfmn_event('test','Provider failed token=abc123 https://example.test/private');
 sfmn_event('test','Provider failed token=abc123 https://example.test/private');
 $rows=json_decode(file_get_contents($dir.'/events.json'),true,64,JSON_THROW_ON_ERROR);
 if(count($rows)!==1||$rows[0]['count']!==2||str_contains($rows[0]['message'],'abc123')||str_contains($rows[0]['message'],'example.test'))throw new Exception('Dedupe or redaction failed');
 sfmn_event('download.failed.test','Failed');$rows=json_decode(file_get_contents($dir.'/events.json'),true);$rows[1]['read']=true;sfmn_write($dir.'/events.json',$rows);sfmn_event('download.failed.test','Failed');$rows=json_decode(file_get_contents($dir.'/events.json'),true);if(count($rows)!==2||!$rows[1]['read'])throw new Exception('Old job notified again');
 for($i=0;$i<205;$i++)sfmn_event('limit.'.$i,'Fixture');
 if(count(json_decode(file_get_contents($dir.'/events.json'),true))!==200)throw new Exception('Retention failed');
 $failed=false;try{sfmn_request('arbitrary_url');}catch(InvalidArgumentException $e){$failed=true;}if(!$failed)throw new Exception('Transport allowlist failed');
 echo "MORNYE_CONTROL_TESTS_OK\n";
}finally{foreach(glob($dir.'/*') as $f)unlink($f);if(is_file($dir.'/.lock'))unlink($dir.'/.lock');rmdir($dir);}
