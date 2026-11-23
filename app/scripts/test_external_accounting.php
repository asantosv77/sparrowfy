<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$project=dirname(__DIR__);$fixture=$project.'/data/external-ai/accounting-test-'.bin2hex(random_bytes(8));mkdir($fixture,0700,true);define('SFEM_TEST_ROOT',$fixture);require $project.'/lib/external_ai.php';
function ac_check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name."\n";}
try{
 $p=['url'=>'https://api.openai.com/v1/chat/completions','protocol'=>'openai','model'=>'fixture-model','pricing'=>['model'=>'fixture-model','input'=>2,'cached'=>1,'output'=>8,'cache_write'=>2.5,'cache_write_1h'=>4]];
 $usage=['prompt_tokens'=>1000,'completion_tokens'=>200,'total_tokens'=>1200,'prompt_tokens_details'=>['cached_tokens'=>100],'completion_tokens_details'=>['reasoning_tokens'=>50]];
 $r=sfem_accounting($p,['usage'=>$usage],['x-ratelimit-remaining-tokens'=>'3000']);
 ac_check($r['total_tokens']===1200&&$r['reasoning_tokens']===50,'reasoning and cache do not double-count total tokens');
 ac_check(abs($r['cost']['amount']-.0035)<1e-10&&$r['cost']['basis']==='estimated','USD estimate uses noncached/cached/output rates');
 ac_check($r['rate_limit_remaining_tokens']===3000&&!isset($r['balance']),'rate limit is separate from account balance');
 $missing=$p;unset($missing['pricing']['output']);ac_check(sfem_accounting($missing,['usage'=>$usage])['cost']['amount']===null,'missing price is unknown, not zero');
 $mismatch=$p;$mismatch['pricing']['model']='other-model';ac_check(sfem_accounting($mismatch,['usage'=>$usage])['cost']['amount']===null,'rates from another model are not reused');
 ac_check(sfem_accounting($p,[])['total_tokens']===null&&sfem_accounting($p,[])['cost']['amount']===null,'missing API usage is unknown, not zero');
 $claude=$p;$claude['protocol']='anthropic';$claude['url']='https://api.anthropic.com/v1/messages';$cu=['input_tokens'=>100,'output_tokens'=>20,'cache_read_input_tokens'=>50,'cache_creation_input_tokens'=>10];$r=sfem_accounting($claude,['usage'=>$cu]);
 ac_check($r['total_tokens']===180&&abs($r['cost']['amount']-.000435)<1e-10,'Anthropic cache categories included exactly once');
 $cu['cache_creation']=['ephemeral_5m_input_tokens'=>8,'ephemeral_1h_input_tokens'=>2];$r=sfem_accounting($claude,['usage'=>$cu]);ac_check(abs($r['cost']['amount']-.000438)<1e-10,'cache creation TTL prices kept separate');
 $grok=$p;$grok['url']='https://api.x.ai/v1/chat/completions';$r=sfem_accounting($grok,['usage'=>['cost_in_usd_ticks'=>37756000]]);ac_check(abs($r['cost']['amount']-.0037756)<1e-10&&$r['cost']['basis']==='provider_reported','xAI exact cost ticks converted to USD');
 $deep=$p;$deep['url']='https://api.deepseek.com/chat/completions';$r=sfem_balance($deep,static fn()=>['balance_infos'=>[['currency'=>'USD','total_balance'=>'10.23']]]);ac_check($r['status']==='reported'&&$r['balances'][0]['amount']==='10.23','DeepSeek monetary balance preserves provider currency');
 $r=sfem_balance($deep,static function(){throw new RuntimeException('offline');});ac_check($r['status']==='not_available'&&$r['balances']===[],'balance lookup failure does not invent a zero balance');
 ac_check(sfem_balance($p)['status']==='not_available','OpenAI normal integration does not claim account balance');
 $p['token']=sfem_encrypt('test-key-never-used');$observed=null;$thrown=false;
 try{sfem_ai_request($p,'skill','context',2000,static fn()=>['usage'=>$usage,'choices'=>[['finish_reason'=>'stop','message'=>['content'=>'INVALID JSON']]]],static function($usage,$accounting)use(&$observed){$observed=$accounting;});}catch(JsonException $e){$thrown=true;}
 ac_check($thrown&&$observed['total_tokens']===1200,'usage recorded before invalid model JSON is rejected');
 $observed=null;try{sfem_ai_request($p,'skill','context',2000,static fn()=>['usage'=>$usage,'choices'=>[['finish_reason'=>'length']]],static function($usage,$accounting)use(&$observed){$observed=$accounting;});}catch(RuntimeException $e){}
 ac_check($observed['total_tokens']===1200,'usage survives truncated output rejection');
 echo "All accounting tests passed without live API calls.\n";
}finally{if(str_starts_with($fixture,$project.'/data/external-ai/accounting-test-')){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($fixture);}}
