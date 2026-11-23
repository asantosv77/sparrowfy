<?php
declare(strict_types=1);
/** Bounded retry policy. Never retry uncertain/running/cancelled submissions. */
function sfmn_retry_plan(array $state,string $status,string $error,array $settings,array $available):?array {
 if($status!=='failed'||!empty($state['submitting'])||(int)($state['retry_count']??0)>=2)return null;
 $providers=array_values(array_filter((array)($settings['services']??[]),static fn($p)=>is_string($p)&&in_array($p,$available,true)));
 if(empty($settings['allow_fallback']))$providers=array_slice($providers,0,1);
 if(!$providers)return null;
 preg_match_all('/(?:ext:|provider[ \"\']+)([a-z0-9_-]+)/i',$error,$matches);
 $failed=array_unique(array_merge((array)($state['failed_providers']??[]),$matches[1]??[]));
 foreach($failed as $name)foreach($providers as $provider)if($name===$provider||str_starts_with($name,$provider.'-'))$failed[]=$provider;
 $failed=array_unique($failed);
 $remaining=array_values(array_diff($providers,$failed));
 if(!empty($settings['allow_fallback'])&&$failed&&$remaining)return ['services'=>$remaining,'reason'=>'probando otros proveedores','failed_providers'=>$failed];
 if((int)($state['retry_count']??0)===0&&preg_match('/timeout|timed out|temporar|connection|network|HTTP (?:408|500|502|503|504)/i',$error)&&!preg_match('/verif|captcha|challenge|unauthor|forbidden|HTTP (?:401|403|429)/i',$error))return ['services'=>$providers,'reason'=>'reintentando un fallo temporal','failed_providers'=>$failed];
 return null;
}
