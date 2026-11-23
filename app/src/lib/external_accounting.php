<?php
declare(strict_types=1);
/** Usage is API metadata, never text invented by the reviewing model. */
function sfem_count(mixed $value):?int{return is_numeric($value)&&is_finite((float)$value)&&(float)$value>=0?(int)$value:null;}
function sfem_accounting(array $provider,array $response,array $headers=[]):array{
 $u=is_array($response['usage']??null)?$response['usage']:[];$anthropic=($provider['protocol']??'')==='anthropic';
 $input=sfem_count($u['prompt_tokens']??$u['input_tokens']??null);$output=sfem_count($u['completion_tokens']??$u['output_tokens']??null);
 $cached=sfem_count($u['prompt_tokens_details']['cached_tokens']??$u['input_tokens_details']['cached_tokens']??$u['prompt_cache_hit_tokens']??$u['cache_read_input_tokens']??0);
 $created=sfem_count($u['cache_creation_input_tokens']??0);$reasoning=sfem_count($u['completion_tokens_details']['reasoning_tokens']??$u['output_tokens_details']['reasoning_tokens']??0);
 $total=sfem_count($u['total_tokens']??null);if($total===null&&$input!==null&&$output!==null)$total=$input+$output+($anthropic?($cached??0)+($created??0):0);
 $cost=['amount'=>null,'currency'=>'USD','basis'=>'not_reported'];$host=strtolower((string)parse_url($provider['url']??'',PHP_URL_HOST));
 if($host==='api.x.ai'&&is_numeric($u['cost_in_usd_ticks']??null)&&(float)$u['cost_in_usd_ticks']>=0){$cost=['amount'=>(float)$u['cost_in_usd_ticks']/1e10,'currency'=>'USD','basis'=>'provider_reported','ticks'=>(string)$u['cost_in_usd_ticks']];}
 elseif($input!==null&&$output!==null&&$cached!==null&&$created!==null){
  $rates=$provider['pricing']??[];if(($rates['model']??'')===($provider['model']??'')){
   $parts=['input'=>$anthropic?$input:max(0,$input-$cached),'cached'=>$cached,'output'=>$output];
   if($created>0){$short=sfem_count($u['cache_creation']['ephemeral_5m_input_tokens']??null);$long=sfem_count($u['cache_creation']['ephemeral_1h_input_tokens']??null);if($short!==null&&$long!==null&&$short+$long===$created){$parts['cache_write']=$short;$parts['cache_write_1h']=$long;}else{$parts['cache_write']=$created;}}
   $amount=0;$complete=true;foreach($parts as $kind=>$tokens){if(!$tokens)continue;if(!is_numeric($rates[$kind]??null)||(float)$rates[$kind]<0){$complete=false;break;}$amount+=$tokens*(float)$rates[$kind]/1e6;}
   if($complete)$cost=['amount'=>round($amount,10),'currency'=>'USD','basis'=>'estimated','rates'=>$rates];
  }
 }
 $limit=sfem_count($headers['x-ratelimit-remaining-tokens']??$headers['anthropic-ratelimit-tokens-remaining']??null);
 return ['recorded_at'=>gmdate('c'),'model'=>$provider['model']??'','response_model'=>is_string($response['model']??null)?$response['model']:null,'input_tokens'=>$input,'cached_input_tokens'=>$cached,'cache_creation_tokens'=>$created,'output_tokens'=>$output,'reasoning_tokens'=>$reasoning,'total_tokens'=>$total,'cost'=>$cost,'rate_limit_remaining_tokens'=>$limit,'rate_limit_reset'=>$headers['x-ratelimit-reset-tokens']??$headers['anthropic-ratelimit-tokens-reset']??null];
}
function sfem_billing_portal(array $provider):?string{$host=strtolower((string)parse_url($provider['url']??'',PHP_URL_HOST));return match($host){'api.openai.com'=>'https://platform.openai.com/settings/organization/billing/overview','api.x.ai'=>'https://console.x.ai/','api.deepseek.com'=>'https://platform.deepseek.com/usage','api.anthropic.com'=>'https://platform.claude.com/settings/billing',default=>null};}
function sfem_balance(array $provider,?callable $transport=null):array{
 $base=['status'=>'not_available','checked_at'=>gmdate('c'),'balances'=>[],'portal'=>sfem_billing_portal($provider),'message'=>'El proveedor no expone un saldo de cuenta mediante esta integración. No es un saldo de cero.'];
 if(strtolower((string)parse_url($provider['url']??'',PHP_URL_HOST))!=='api.deepseek.com')return $base;
 try{
  if($transport)$d=$transport();else{
   [$host,$ips]=sfem_validate_url('https://api.deepseek.com/user/balance');$address=str_contains($ips[0],':')?'['.$ips[0].']':$ips[0];$ch=curl_init('https://api.deepseek.com/user/balance');$text='';curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.sfem_decrypt($provider['token']),'Accept: application/json'],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>[$host.':443:'.$address],CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$text){if(strlen($text)+strlen($chunk)>65536)return 0;$text.=$chunk;return strlen($chunk);}]);$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($ok===false||$status!==200)throw new RuntimeException('balance_unavailable');$d=json_decode($text,true,32,JSON_THROW_ON_ERROR);
  }
  foreach($d['balance_infos']??[] as $entry)if(in_array($entry['currency']??'',['USD','CNY'],true)&&is_numeric($entry['total_balance']??null))$base['balances'][]=['currency'=>$entry['currency'],'amount'=>(string)$entry['total_balance']];
  if(!$base['balances'])throw new RuntimeException('balance_format_changed');$base['status']='reported';$base['message']='Saldo informado por el proveedor al terminar esta operación.';
 }catch(Throwable $e){$base['message']='No se pudo consultar el saldo; esto no invalida el mantenimiento ni significa saldo cero.';}
 return $base;
}
