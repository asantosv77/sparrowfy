<?php
declare(strict_types=1);
require_once __DIR__.'/external_ai.php';
function sfem_model_endpoint(array $provider):string{
 $url=$provider['url'];$p=parse_url($url);$path=rtrim($p['path']??'','/');
 if(str_ends_with($path,'/chat/completions'))$path=substr($path,0,-17).'/models';
 elseif(str_ends_with($path,'/messages'))$path=substr($path,0,-9).'/models';
 else throw new RuntimeException('No se pudo deducir el catálogo de este proveedor. Escribe un modelo manualmente.');
 return 'https://'.$p['host'].$path;
}
function sfem_model_candidate(string $id,array $provider):bool{
 if(!preg_match('/^[a-zA-Z0-9._:\/-]{1,120}$/D',$id))return false;
 if(preg_match('/audio|realtime|transcrib|whisper|tts|image|dall|embedding|moderation|deep-research|search-preview|computer-use/i',$id))return false;
 $host=strtolower((string)parse_url($provider['url'],PHP_URL_HOST));
 if($host==='api.openai.com')return str_starts_with($id,'gpt-')&&!preg_match('/codex|(?:^|-)pro(?:-|$)/i',$id);
 return true;
}
function sfem_pick_model(array $models,array $provider):?string{
 $host=strtolower((string)parse_url($provider['url'],PHP_URL_HOST));$rank=[];
 foreach($models as $m){$id=$m['id'];$score=0;$version=0;
  if($host==='api.openai.com'){
   if(preg_match('/^gpt-(\d+(?:\.\d+)?)(-mini)?$/D',$id,$match)){$score=isset($match[2])?30:20;$version=(float)$match[1];}
   elseif($id==='gpt-4o-mini'){$score=10;$version=4;}elseif($id==='gpt-4o'){$score=5;$version=4;}
  }elseif($host==='api.anthropic.com'&&str_starts_with($id,'claude-')){$score=str_contains($id,'sonnet')?30:(str_contains($id,'haiku')?20:10);}
  elseif($host==='api.deepseek.com'&&str_starts_with($id,'deepseek')){$score=$id==='deepseek-chat'?30:(str_contains($id,'flash')?20:(!str_contains($id,'reasoner')?10:0));}
  elseif($host==='api.x.ai'&&preg_match('/^grok-(\d+(?:[.-]\d+)?)/',$id,$match)){$score=str_contains($id,'non-reasoning')?30:(str_contains($id,'fast')?20:10);$version=(float)str_replace('-','.',$match[1]);}
  if($score)$rank[]=['id'=>$id,'score'=>$score,'version'=>$version,'created'=>$m['created']??0];
 }
 if(!$rank)return count($models)===1?$models[0]['id']:null;
 usort($rank,static fn($a,$b)=>($b['score']<=>$a['score'])?:($b['version']<=>$a['version'])?:($b['created']<=>$a['created'])?:strcmp($a['id'],$b['id']));return $rank[0]['id'];
}
function sfem_models(array $provider,bool $force=false,?callable $transport=null):array{
 if(empty($provider['token']))throw new RuntimeException('Guarda primero el token del proveedor.');
 $cache=sfem_dir().'/models-'.hash('sha256',$provider['url'].'|'.$provider['protocol'].'|'.$provider['token']).'.json';$cached=sfem_read_json($cache);
 if(!$transport&&!$force&&($cached['checked_at']??0)>time()-3600)return $cached;
 $url=sfem_model_endpoint($provider);
 if($transport)$data=$transport($url);else{
  [$host,$ips]=sfem_validate_url($url);$address=str_contains($ips[0],':')?'['.$ips[0].']':$ips[0];$headers=['Accept: application/json'];$key=sfem_decrypt($provider['token']);
  if($provider['protocol']==='anthropic'){$headers[]='x-api-key: '.$key;$headers[]='anthropic-version: 2023-06-01';}else $headers[]='Authorization: Bearer '.$key;
  $ch=curl_init($url);$text='';curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>[$host.':443:'.$address],CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$text){if(strlen($text)+strlen($chunk)>1048576)return 0;$text.=$chunk;return strlen($chunk);}]);$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($ok===false||$status!==200)throw new RuntimeException('No se pudo consultar el catálogo de modelos. Revisa permisos del token o elige un identificador manual.');$data=json_decode($text,true,64,JSON_THROW_ON_ERROR);
 }
 if(!is_array($data['data']??null))throw new RuntimeException('El proveedor cambió el formato del catálogo de modelos.');$models=[];
 foreach($data['data'] as $m){if(!is_array($m)||!is_string($m['id']??null)||!sfem_model_candidate($m['id'],$provider))continue;$models[$m['id']]=['id'=>$m['id'],'name'=>is_string($m['display_name']??$m['name']??null)?($m['display_name']??$m['name']):$m['id'],'created'=>is_numeric($m['created']??null)?(int)$m['created']:(is_string($m['created_at']??null)?(strtotime($m['created_at'])?:0):0)];}
 $models=array_values($models);usort($models,static fn($a,$b)=>strcmp($a['id'],$b['id']));$result=['checked_at'=>time(),'models'=>array_slice($models,0,300),'suggested'=>sfem_pick_model($models,$provider)];if(!$transport)sfem_json($cache,$result);return $result;
}
function sfem_resolve_model(string $id):array{
 $settings=sfem_settings();$provider=$settings['providers'][$id]??[];if(!empty($provider['model']))return $provider;
 $catalog=sfem_models($provider);$chosen=$catalog['suggested'];if(!$chosen)throw new RuntimeException('No hay un modelo predeterminado compatible identificable. Abre el catálogo y elige uno manualmente.');
 $lock=fopen(sfem_dir().'/settings.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('No se pudo guardar el modelo.');try{$latest=sfem_settings();$current=$latest['providers'][$id]??[];
 if(($current['url']??'')!==$provider['url']||($current['token']??'')!==$provider['token'])throw new RuntimeException('La configuración del proveedor cambió; vuelve a procesar.');
 if(empty($current['model'])){$current['model']=$chosen;$current['model_resolved_at']=gmdate('c');$current['model_selection']='automatic_first_run';unset($current['pricing']);$latest['providers'][$id]=$current;sfem_json(sfem_dir().'/settings.json',$latest);}return $current;}finally{flock($lock,LOCK_UN);fclose($lock);}
}
