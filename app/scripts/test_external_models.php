<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$project=dirname(__DIR__);$fixture=$project.'/data/external-ai/models-test-'.bin2hex(random_bytes(8));mkdir($fixture,0700,true);define('SFEM_TEST_ROOT',$fixture);require $project.'/lib/external_models.php';
function model_check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name."\n";}
try{
 $p=['url'=>'https://api.openai.com/v1/chat/completions','protocol'=>'openai','token'=>'fixture-only','model'=>''];
 model_check(sfem_model_endpoint($p)==='https://api.openai.com/v1/models','OpenAI model catalog path');
 $deep=$p;$deep['url']='https://api.deepseek.com/chat/completions';model_check(sfem_model_endpoint($deep)==='https://api.deepseek.com/models','DeepSeek model catalog path');
 $claude=$p;$claude['url']='https://api.anthropic.com/v1/messages';model_check(sfem_model_endpoint($claude)==='https://api.anthropic.com/v1/models','Claude model catalog path');
 $data=['data'=>[['id'=>'gpt-5-mini'],['id'=>'gpt-5.1-mini'],['id'=>'gpt-5.2'],['id'=>'gpt-image-1'],['id'=>'tts-1'],['id'=>'gpt-5-pro'],['id'=>'gpt-5.1-codex']]];
 $catalog=sfem_models($p,false,static fn()=>$data);model_check(count($catalog['models'])===3,'nonchat/image/pro/codex models excluded');model_check($catalog['suggested']==='gpt-5.1-mini','automatic choice favors an available compact text model');
 $empty=sfem_models($p,false,static fn()=>['data'=>[]]);model_check($empty['suggested']===null,'empty catalog never fabricates a model');
 $custom=$p;$custom['url']='https://api.example.com/v1/chat/completions';$list=sfem_models($custom,false,static fn()=>['data'=>[['id'=>'one'],['id'=>'two']]]);model_check($list['suggested']===null,'ambiguous custom provider requires manual choice');
 $list=sfem_models($custom,false,static fn()=>['data'=>[['id'=>'only-model']]]);model_check($list['suggested']==='only-model','single custom model can be selected');
 $list=sfem_models($claude,false,static fn()=>['data'=>[['id'=>'claude-opus-4-5'],['id'=>'claude-sonnet-4-5']]]);model_check($list['suggested']==='claude-sonnet-4-5','Claude balanced family preference');
 $failed=false;try{sfem_models($p,false,static fn()=>['changed'=>[]]);}catch(Throwable $e){$failed=true;}model_check($failed,'catalog schema failure handled');
 sfem_save_settings(['provider'=>'openai','name'=>'OpenAI','url'=>$p['url'],'protocol'=>'openai','model'=>'','token'=>'test-token-not-real','interval_days'=>7,'daily'=>1,'auto_apply'=>1]);$provider=sfem_settings()['providers']['openai'];model_check($provider['model']===''&&!empty($provider['token']),'token can be saved with automatic blank model');model_check(sfem_settings()['interval_days']===7,'review interval saved as integer days');foreach([0,-1,'1.5',3651] as $bad){$rejected=false;try{sfem_save_settings(['provider'=>'openai','model'=>'','interval_days'=>$bad]);}catch(RuntimeException $e){$rejected=true;}model_check($rejected&&sfem_settings()['interval_days']===7,'invalid interval rejected without replacing saved preferences');}
 $cache=sfem_dir().'/models-'.hash('sha256',$provider['url'].'|'.$provider['protocol'].'|'.$provider['token']).'.json';sfem_json($cache,['checked_at'=>time(),'models'=>$catalog['models'],'suggested'=>$catalog['suggested']]);
 $resolved=sfem_resolve_model('openai');model_check($resolved['model']==='gpt-5.1-mini','first-run resolution uses available cached catalog');model_check(sfem_settings()['providers']['openai']['model']==='gpt-5.1-mini','resolved choice persisted to settings');
 $again=sfem_resolve_model('openai');model_check($again['model']===$resolved['model'],'later operations retain the selected model');
 echo "All model discovery tests passed without live tokens or inference.\n";
}finally{if(str_starts_with($fixture,$project.'/data/external-ai/models-test-')){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($fixture);}}
