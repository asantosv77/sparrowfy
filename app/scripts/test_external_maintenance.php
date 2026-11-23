<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$project=dirname(__DIR__);$fixture=$project.'/data/external-ai/test-'.bin2hex(random_bytes(8));mkdir($fixture,0700,true);mkdir($fixture.'/lib',0700);
foreach(['lib/external_charts.php','lib/external_charts_audio.php','lib/external_mornye.php','lib/external_services.php','lib/external_youtube.php','lib/external_cover.php','lib/external_lyrics.php','lib/external_album.php','lib/acoustid_health.php','youtube_url_normalizer.php'] as $file)copy($project.'/'.$file,$fixture.'/'.$file);
define('SFEM_TEST_ROOT',$fixture);require $project.'/lib/external_ai.php';require $project.'/lib/external_validation.php';
function expect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message."\n";}
function rejects(callable $fn,string $message):void{try{$fn();}catch(Throwable $e){expect(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
try{
 $sources=sfem_sources();$base=sfem_hashes();$file='lib/external_services.php';
 $changed=str_replace('function sf_external_youtube_search(string $query,?callable $runner=null,int $limit=2):array {','function sf_external_youtube_search(string $query,?callable $runner=null,int $limit=2):array { /* isolated version test */',$sources[$file]);
 expect($changed!==$sources[$file],'fixture changes an allowed function body');
 expect(sfem_validate([$file=>$changed])['isolated_contracts'],'isolated validator accepts compatible code');
 $result=sfem_apply([$file=>$changed],$base,'test','test version');expect(sfem_hashes()!==$base,'versioned publication in fixture');expect(sfem_version_sources($result['backup'])===$sources,'backup preserves complete originals');
 rejects(static fn()=>sfem_apply([$file=>$sources[$file]],$base,'test','stale'),'stale editor revision rejected');
 sfem_apply(sfem_version_sources($result['backup']),sfem_hashes(),'test','restore');expect(sfem_sources()===$sources,'restoration preserves all original files');
 rejects(static fn()=>sfem_validate(['auth.php'=>'<?php echo 1;']),'out-of-scope file rejected');
 rejects(static fn()=>sfem_validate([$file=>$sources[$file]."\necho 'bad';"]),'top-level execution rejected');
 $bad=str_replace('/* isolated version test */','getenv("SECRET");',$changed);rejects(static fn()=>sfem_validate([$file=>$bad]),'new environment access rejected');
 $bad=str_replace('function yt_bin(): string {','function yt_bin(): string { /* changed */',$sources['lib/external_youtube.php']);rejects(static fn()=>sfem_validate(['lib/external_youtube.php'=>$bad]),'protected transport changes rejected');
 $bad=str_replace('return $items;','return [];',$sources[$file]);rejects(static fn()=>sfem_validate([$file=>$bad]),'broken search contract rejected before publication');
 expect(sfem_sources()===$sources,'rejected proposals never change published source');
 $encrypted=sfem_encrypt('test-api-key-not-real');expect(sfem_decrypt($encrypted)==='test-api-key-not-real'&&!str_contains($encrypted,'test-api-key'),'token encryption round trip');
 sfem_save_settings(['provider'=>'openai','name'=>'OpenAI','url'=>'https://api.openai.com/v1/chat/completions','protocol'=>'openai','model'=>'test-model','token'=>'test-api-key-not-real','daily'=>1,'auto_apply'=>1]);
 expect(!str_contains(json_encode(sfem_public_settings()),'test-api-key-not-real')&&!isset(sfem_public_settings()['providers']['openai']['token']),'public settings never return saved tokens');
 $provider=sfem_settings()['providers']['openai'];$calls=0;$transport=static function($url,$body,$headers)use(&$calls){$calls++;expect($body['response_format']['type']==='json_object','JSON response requested');return ['choices'=>[['finish_reason'=>'stop','message'=>['content'=>'{"summary":"Sin cambios","files":{}}']]],'usage'=>['total_tokens'=>10]];};
 $proposal=sfem_ai_request($provider,'skill','context',2000,$transport);expect($calls===1&&$proposal['files']===[],'OpenAI-compatible response parsed without network');
 $provider['protocol']='anthropic';$proposal=sfem_ai_request($provider,'skill','context',2000,static fn()=>['stop_reason'=>'end_turn','content'=>[['type'=>'text','text'=>'{"summary":"Sin cambios","files":{}}']]]);expect($proposal['summary']==='Sin cambios','Claude response parsed without network');
 $provider['protocol']='openai';rejects(static fn()=>sfem_ai_request($provider,'skill','context',2000,static fn()=>['choices'=>[['finish_reason'=>'length']]]),'truncated AI output rejected');
 foreach(['http://api.example.com/chat','https://127.0.0.1/chat','https://localhost/chat','https://metadata.google.internal/chat','https://api.example.com:8080/chat','https://user:pass@api.example.com/chat'] as $url)rejects(static fn()=>sfem_validate_url($url,false),'unsafe endpoint rejected');
 expect(count(sfem_versions())>=2,'version history remains available');echo "All maintenance tests passed in isolated fixture; no paid API calls.\n";
}finally{
 if(str_starts_with($fixture,$project.'/data/external-ai/test-')&&is_dir($fixture)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($fixture);}
}
