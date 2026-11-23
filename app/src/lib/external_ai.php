<?php
declare(strict_types=1);
require_once __DIR__.'/admin_external.php';
require_once __DIR__.'/external_accounting.php';
function sfem_validate_url(string $url,bool $resolve=true):array{
 $p=parse_url($url);if(!is_array($p)||($p['scheme']??'')!=='https'||empty($p['host'])||!empty($p['user'])||!empty($p['pass'])||isset($p['query'])||isset($p['fragment'])||(isset($p['port'])&&(int)$p['port']!==443))throw new RuntimeException('Usa un endpoint HTTPS sin credenciales, query ni puertos alternos.');
 $host=strtolower($p['host']);if(!preg_match('/^[a-z0-9.-]+$/D',$host)||!str_contains($host,'.')||str_ends_with($host,'.local')||str_ends_with($host,'.internal')||str_ends_with($host,'.localhost')||filter_var($host,FILTER_VALIDATE_IP))throw new RuntimeException('Host público de API inválido.');
 if(!$resolve)return [$host,[]];
 $records=dns_get_record($host,DNS_A|DNS_AAAA);$ips=[];foreach($records?:[] as $record){$ip=$record['ip']??$record['ipv6']??null;if(!$ip)continue;if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('El endpoint resuelve a una dirección no pública.');$ips[]=$ip;}
 if(!$ips)throw new RuntimeException('No se pudo resolver el proveedor de IA.');return [$host,array_values(array_unique($ips))];
}
function sfem_ai_request(array $provider,string $system,string $user,int $limit,?callable $transport=null,?callable $onUsage=null):array{
 $responseHeaders=[];$protocol=$provider['protocol'];$body=['model'=>$provider['model'],'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]]];$headers=['Content-Type: application/json'];
 if($protocol==='anthropic'){$body=['model'=>$provider['model'],'system'=>$system,'messages'=>[['role'=>'user','content'=>$user]],'max_tokens'=>$limit];$headers[]='x-api-key: '.sfem_decrypt($provider['token']);$headers[]='anthropic-version: 2023-06-01';}
 else{$headers[]='Authorization: Bearer '.sfem_decrypt($provider['token']);$body[$protocol==='openai'?'max_completion_tokens':'max_tokens']=$limit;$body['response_format']=['type'=>'json_object'];if($protocol==='openai')$body['store']=false;}
 if($transport){$result=$transport($provider['url'],$body,$headers);}
 else{
  if(!function_exists('curl_init'))throw new RuntimeException('cURL no disponible.');[$host,$ips]=sfem_validate_url($provider['url']);$ip=$ips[0];$address=str_contains($ip,':')?'['.$ip.']':$ip;$ch=curl_init($provider['url']);$text='';
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>120,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$address],CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$responseHeaders){$pair=explode(':',$line,2);$key=strtolower(trim($pair[0]));if(count($pair)===2&&in_array($key,['x-ratelimit-remaining-tokens','x-ratelimit-reset-tokens','anthropic-ratelimit-tokens-remaining','anthropic-ratelimit-tokens-reset'],true))$responseHeaders[$key]=substr(trim($pair[1]),0,100);return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$text){if(strlen($text)+strlen($chunk)>1572864)return 0;$text.=$chunk;return strlen($chunk);}]);
  $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($ok===false)throw new RuntimeException('El proveedor no respondió dentro del límite o devolvió una respuesta excesiva.');if($code<200||$code>=300)throw new RuntimeException('El proveedor de IA rechazó la solicitud (HTTP '.$code.'). Revisa token, modelo, saldo y límites.');$result=json_decode($text,true,64,JSON_THROW_ON_ERROR);
 }
 if(!is_array($result))throw new RuntimeException('Respuesta de IA inválida.');
 if($onUsage)$onUsage(is_array($result['usage']??null)?$result['usage']:[],sfem_accounting($provider,$result,$responseHeaders));
 if($protocol==='anthropic'){$text='';foreach($result['content']??[] as $block)if(($block['type']??'')==='text')$text.=$block['text']??'';if(($result['stop_reason']??'')==='max_tokens')throw new RuntimeException('Respuesta truncada; no se aplicó código.');}
 else{if(($result['choices'][0]['finish_reason']??'')!=='stop')throw new RuntimeException('Respuesta incompleta o rechazada; no se aplicó código.');$text=$result['choices'][0]['message']['content']??'';}
 if(!is_string($text)||trim($text)==='')throw new RuntimeException('La IA no devolvió una propuesta.');$text=preg_replace('/^```(?:json)?\s*|\s*```$/','',trim($text));$proposal=json_decode($text,true,32,JSON_THROW_ON_ERROR);
 if(!is_array($proposal)||!is_string($proposal['summary']??null)||!is_array($proposal['files']??null))throw new RuntimeException('La IA no respetó el contrato JSON de mantenimiento.');
 foreach($proposal['files'] as $file=>$code){sfem_file((string)$file);if(!is_string($code))throw new RuntimeException('La IA devolvió un archivo incompleto.');}
 return ['summary'=>mb_substr($proposal['summary'],0,3000),'files'=>$proposal['files'],'usage'=>is_array($result['usage']??null)?$result['usage']:[],'accounting'=>sfem_accounting($provider,$result,$responseHeaders)];
}
