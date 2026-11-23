<?php

declare(strict_types=1);

require_once __DIR__.'/admin_external.php';

/** Fixed validator outside the editable allowlist. */

function sfem_functions(string $source):array{

 $tokens=token_get_all($source,TOKEN_PARSE);$functions=[];$outside='';$n=count($tokens);

 for($i=0;$i<$n;$i++){$t=$tokens[$i];

  if(is_array($t)&&$t[0]===T_FUNCTION){$text='function';$signature='function';$name='';$depth=0;$body=false;

   for($i++;$i<$n;$i++){$x=$tokens[$i];$v=is_array($x)?$x[1]:$x;if(!$body&&is_array($x)&&$x[0]===T_STRING&&$name==='')$name=$v;$text.=$v;if(!$body)$signature.=$v;if($v==='{'){$depth++;$body=true;}elseif($v==='}'&&$body){$depth--;if($depth===0)break;}}

   if($name==='')throw new RuntimeException('No se permiten nuevas funciones anónimas de nivel superior.');$functions[$name]=['signature'=>preg_replace('/\s+/','',$signature),'body'=>$text];$outside.='FUNCTION:'.$name.';';

  }else{if(is_array($t)&&in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))continue;$outside.=is_array($t)?$t[1]:$t;}

 }return ['functions'=>$functions,'outside'=>$outside];

}

function sfem_calls(string $body):array{

 $tokens=array_values(array_filter(token_get_all('<?php '.$body),static fn($t)=>!is_array($t)||!in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)));$out=[];

 foreach($tokens as $i=>$token){if(!is_array($token))continue;$next=$tokens[$i+1]??null;$prev=$tokens[$i-1]??null;if($token[0]===T_NEW){$class=$tokens[$i+1]??null;$out[]='new:'.(is_array($class)?$class[1]:'dynamic');}if(in_array($token[0],[T_STRING,T_VARIABLE],true)&&$next==='('&&(!is_array($prev)||$prev[0]!==T_FUNCTION))$out[]=strtolower($token[1]);}return array_unique($out);

}

function sfem_static_check(string $file,string $before,string $after):void{

 if(strlen($after)>180000||!str_starts_with(ltrim($after),'<?php'))throw new RuntimeException('Código PHP vacío o demasiado grande.');$old=sfem_functions($before);$new=sfem_functions($after);

 if($old['outside']!==$new['outside']||array_keys($old['functions'])!==array_keys($new['functions']))throw new RuntimeException('Mantén imports, constantes, orden y nombres de funciones; solo se admiten cambios en sus cuerpos.');

 $protected=['sf_external_chart_url','sf_external_mornye_url','sfa_http','sfa_cached','yt_bin','yt_run','yt_cookie_temp','yt_validate_url','cov_get','lyrics_http_get','sf_external_service_state_path','sf_external_service_available','sf_external_service_result'];

 foreach($old['functions'] as $name=>$fn){$candidate=$new['functions'][$name];if($fn['signature']!==$candidate['signature'])throw new RuntimeException('No cambies la firma de '.$name.'.');if($fn['body']===$candidate['body'])continue;if(in_array($name,$protected,true))throw new RuntimeException('La función '.$name.' pertenece a la capa protegida y requiere mantenimiento fuera de este editor.');

  $safe=['is_array','is_string','is_numeric','is_int','is_float','is_bool','is_null','isset','empty','count','strlen','trim','ltrim','rtrim','strtolower','strtoupper','str_contains','str_starts_with','str_ends_with','strpos','stripos','strrpos','substr','str_replace','str_ireplace','explode','implode','sprintf','intval','floatval','max','min','round','floor','ceil','abs','array_filter','array_map','array_values','array_keys','array_unique','array_slice','array_merge','in_array','array_key_exists','preg_match','preg_match_all','preg_replace','preg_split','json_decode','json_encode','mb_substr','mb_strtolower','mb_strlen','html_entity_decode','strip_tags','rawurlencode','urlencode','http_build_query','parse_url'];

  if(array_diff(sfem_calls($candidate['body']),sfem_calls($fn['body']),$safe))throw new RuntimeException('La propuesta introduce llamadas o clases ajenas al adaptador existente.');



  foreach(token_get_all('<?php '.$candidate['body']) as $token)if(is_array($token)&&in_array($token[0],[T_EVAL,T_INCLUDE,T_INCLUDE_ONCE,T_REQUIRE,T_REQUIRE_ONCE,T_EXIT,T_GLOBAL,T_HALT_COMPILER],true))throw new RuntimeException('La propuesta contiene una operación no permitida.');

  foreach(['`','$_ENV','$_SERVER','$_COOKIE','$_SESSION','$GLOBALS','--exec','--config-locations','/proc/','token.key','settings.json'] as $bad)if(str_contains($candidate['body'],$bad)&&!str_contains($fn['body'],$bad))throw new RuntimeException('La propuesta intenta ampliar el acceso del adaptador.');

  $danger='/\b(eval|assert|exec|system|shell_exec|passthru|popen|proc_open|pcntl_exec|putenv|unlink|rename|chmod|file_put_contents|fopen|unserialize|base64_decode|file_get_contents|curl_init|fsockopen|stream_socket_client)\s*\(/i';preg_match_all($danger,$candidate['body'],$a);preg_match_all($danger,$fn['body'],$b);if(array_diff(array_map('strtolower',$a[1]),array_map('strtolower',$b[1])))throw new RuntimeException('No se permiten nuevas operaciones de ejecución, archivos o red.');

 }

}

function sfem_php():string{foreach(['/usr/local/bin/php','/usr/bin/php'] as $p)if(is_executable($p))return $p;throw new RuntimeException('PHP CLI no disponible.');}

function sfem_process(array $args,string $cwd,int $timeout=15):array{

 $p=proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd,['PATH'=>'/usr/local/bin:/usr/bin:/bin','TZ'=>'UTC'],['bypass_shell'=>true]);if(!is_resource($p))throw new RuntimeException('No se pudo ejecutar la validación.');foreach($pipes as $pipe)stream_set_blocking($pipe,false);$out='';$start=microtime(true);$code=-1;

 do{foreach($pipes as $pipe)$out.=(string)stream_get_contents($pipe);$status=proc_get_status($p);if(!$status['running']){$code=$status['exitcode'];break;}if(microtime(true)-$start>$timeout||strlen($out)>65536){proc_terminate($p,9);foreach($pipes as $pipe)fclose($pipe);proc_close($p);throw new RuntimeException('La validación excedió el límite permitido.');}usleep(50000);}while(true);

 foreach($pipes as $pipe){$out.=(string)stream_get_contents($pipe);fclose($pipe);}$closed=proc_close($p);if($code<0)$code=$closed;return ['code'=>$code,'output'=>substr($out,0,6000)];

}



function sfem_validate(array $changes):array{

 $sources=sfem_sources();if(!$changes)throw new RuntimeException('No hay archivos para actualizar.');

 foreach($changes as $file=>$source){sfem_file((string)$file);if(!is_string($source))throw new RuntimeException('Código inválido.');sfem_static_check($file,$sources[$file],$source);$sources[$file]=$source;}

 $tmp=sfem_dir().'/validation-'.bin2hex(random_bytes(8));mkdir($tmp,0700);mkdir($tmp.'/lib',0700);

 try{

  foreach($sources as $file=>$source)file_put_contents($tmp.'/'.$file,$source);

  foreach(['youtube_url_normalizer.php','lib/acoustid_health.php'] as $file)copy(sfem_root().'/'.$file,$tmp.'/'.$file);

  $args=[sfem_php(),'-d','open_basedir='.$tmp,'-d','allow_url_fopen=0','-d','memory_limit=64M','-d','max_execution_time=10','-d','auto_prepend_file=','-d','auto_append_file=','-d','opcache.enable_cli=0','-d','disable_functions=exec,system,shell_exec,passthru,popen,proc_open,pcntl_exec,curl_init,fsockopen,pfsockopen,stream_socket_client,mail,putenv'];

  foreach(array_keys($sources) as $file){$r=sfem_process([...$args,'-l',$tmp.'/'.$file],$tmp);if($r['code']!==0)throw new RuntimeException('Sintaxis PHP inválida en '.$file.'.');}

  $probe=<<<'PHP'

<?php

require __DIR__.'/lib/external_services.php';

require_once __DIR__.'/lib/external_album.php';

$runner=static fn()=>['code'=>0,'stdout'=>json_encode(['entries'=>[['id'=>'abcdefghijk','title'=>'Test','duration'=>180],['id'=>'lmnopqrstuv','title'=>'Test 2']]])];

$r=sf_external_youtube_search('fixture',$runner);

if(count($r)!==2||$r[0]['url']!=='https://www.youtube.com/watch?v=abcdefghijk')throw new RuntimeException('Contrato YouTube inválido');

if(sf_external_youtube_search('fixture',static fn()=>['code'=>0,'stdout'=>'{"entries":[]}'])!==[])throw new RuntimeException('Contrato vacío inválido');

$failed=false;try{sf_external_youtube_search('fixture',static fn()=>['code'=>0,'stdout'=>'<html>bad</html>']);}catch(Throwable $e){$failed=true;}if(!$failed)throw new RuntimeException('JSON inválido no contenido');

if(cov_norm('ABBA')!==cov_norm('abba'))throw new RuntimeException('Portadas inválidas');

if(!is_string(lyrics_clean_title('ABBA - Dancing Queen')))throw new RuntimeException('Letras inválidas');

$rank=sfa_rank(['title'=>'Dancing Queen','artist'=>'ABBA','duration'=>230],[['id'=>'abcdefghijk','title'=>'ABBA Dancing Queen Lyrics','channel'=>'ABBA','duration'=>230],['id'=>'lmnopqrstuv','title'=>'ABBA Dancing Queen karaoke lyrics','channel'=>'ABBA','duration'=>230]]);

if(($rank[0]['id']??'')!=='abcdefghijk'||empty($rank[0]['confident'])||!empty($rank[1]['confident']))throw new RuntimeException('Ranking de álbum inválido');

$fixture=['releases'=>[['id'=>'12345678-1234-1234-1234-123456789abc','title'=>'Album','artist-credit'=>[['name'=>'Artist']],'track-count'=>1]]];

if(count(sfa_search('musicbrainz','Artist','Album','',static fn()=> $fixture))!==1)throw new RuntimeException('Catálogo de álbum inválido');

if(count(sf_external_youtube_search('fixture',static fn()=>['code'=>0,'stdout'=>json_encode(['entries'=>[['id'=>'abcdefghijk','title'=>'A'],['id'=>'lmnopqrstuv','title'=>'B'],['id'=>'12345678901','title'=>'C']]])],3))!==3)throw new RuntimeException('Contrato YouTube tres resultados inválido');

$m=sf_external_mornye_search('fixture',static fn()=>['tracks'=>[['id'=>'1234567890123456789012','title'=>'Song','artists'=>'Artist','duration_ms'=>180000]]]);

if(count($m)!==1||$m[0]['duration']!==180||$m[0]['url']!=='https://open.spotify.com/track/1234567890123456789012')throw new RuntimeException('Contrato Mornye inválido');

if(sf_external_mornye_search('fixture',static fn()=>['tracks'=>[]])!==[])throw new RuntimeException('Mornye vacío inválido');

$bad=false;try{sf_external_mornye_url('http://127.0.0.1/');}catch(Throwable $e){$bad=true;}if(!$bad)throw new RuntimeException('URL Mornye insegura');

$chart=sf_external_chart_normalize('apple','mx',['feed'=>['country'=>'mx','updated'=>'2026-11-15','results'=>[['id'=>'123','name'=>'Song','artistName'=>'Artist','url'=>'https://music.apple.com/mx/song/123']]]]);

if(count($chart['items'])!==1||$chart['period_kind']!=='current_snapshot')throw new RuntimeException('Contrato Charts inválido');

if(!sf_chart_candidate(['title'=>'Song','artist'=>'Artist'],['title'=>'Artist - Song (Official Audio)','channel'=>'Artist','duration'=>180],'youtube')['confident']||sf_chart_candidate(['title'=>'Song','artist'=>'Artist'],['title'=>'Song live','artist'=>'Artist'],'mornye')['confident'])throw new RuntimeException('Coincidencia Charts inválida');

$christian=sf_external_chart_normalize('itunes_christian','mx',['feed'=>['updated'=>['label'=>'2026-11-15'],'entry'=>[['im:name'=>['label'=>'Song'],'im:artist'=>['label'=>'Artist'],'id'=>['label'=>'https://music.apple.com/mx/song/123','attributes'=>['im:id'=>'123']],'im:duration'=>['label'=>'30000']]]]]);
if($christian['items'][0]['duration']!==0)throw new RuntimeException('Vista previa no es duración');
$weekly=sf_external_chart_normalize('billboard_christian','us',['html'=>'Week of November 14, 2026<div class="o-chart-results-list-row-container"><ul data-detail-target="1"><h3 id="title-of-a-story">Song</h3><span>Artist</span><span>LW</span><ul><li><span>2</span></li></ul></ul></div>']);
if($weekly['issue_date']!=='2026-11-14'||$weekly['items'][0]['official_previous_rank']!==2)throw new RuntimeException('Contrato Billboard inválido');
$dz=sf_external_chart_normalize('deezer','pop',['data'=>[['id'=>123,'title'=>'Song','artist'=>['name'=>'Artist'],'position'=>1,'duration'=>180,'link'=>'https://www.deezer.com/track/123']]]);
if($dz['source_updated']!==null||$dz['items'][0]['genres']!==['Pop']||$dz['items'][0]['duration']!==180)throw new RuntimeException('Contrato Deezer inválido');
$lp=sf_external_chart_normalize('billboard_latin_pop','us',['html'=>'Week of November 14, 2026<div class="o-chart-results-list-row-container"><ul data-detail-target="1"><h3 id="title-of-a-story">Song</h3><span>Artist</span></ul></div>']);
if($lp['items'][0]['genres']!==['Latin Pop']||!str_contains($lp['source_url'],'hot-latin-pop-songs'))throw new RuntimeException('Contrato pop latino inválido');
echo 'VALIDATION_OK';

PHP;

  file_put_contents($tmp.'/probe.php',$probe);$r=sfem_process([...$args,$tmp.'/probe.php'],$tmp,15);if($r['code']!==0||trim($r['output'])!=='VALIDATION_OK')throw new RuntimeException('La propuesta no superó las pruebas aisladas de integración.');

  return ['syntax'=>true,'isolated_contracts'=>true,'network_disabled'=>true];

 }finally{foreach(glob($tmp.'/lib/*')?:[] as $f)@unlink($f);@rmdir($tmp.'/lib');foreach(glob($tmp.'/*')?:[] as $f)if(is_file($f))@unlink($f);@rmdir($tmp);}

}

function sfem_apply(array $changes,array $expected,string $actor,string $reason):array{

 $lock=fopen(sfem_dir().'/apply.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Hay otra actualización en curso.');

 try{

  if(sfem_hashes()!==$expected)throw new RuntimeException('El código cambió desde que se abrió o revisó. Recarga antes de continuar.');

  $checks=sfem_validate($changes);

  // Re-check after validation because the isolated PHP runs can take several seconds.

  if(sfem_hashes()!==$expected)throw new RuntimeException('El código cambió durante la validación. Recarga antes de continuar.');

  $version=sfem_snapshot($actor,$reason);$before=sfem_sources();

  try{foreach($changes as $file=>$source)sfem_write(sfem_file($file),$source);}

  catch(Throwable $e){foreach($before as $file=>$source)sfem_write(sfem_file($file),$source);throw new RuntimeException('Se revirtió la actualización porque no pudo publicarse completa.',0,$e);}

  return ['backup'=>$version,'checks'=>$checks,'hashes'=>sfem_hashes()];

 }finally{flock($lock,LOCK_UN);fclose($lock);}

}

