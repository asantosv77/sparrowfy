<?php
declare(strict_types=1);if(PHP_SAPI!=='cli')exit;
$project=dirname(__DIR__);$fixture=sys_get_temp_dir().'/sfc-render-'.bin2hex(random_bytes(5));mkdir($fixture.'/lib',0700,true);mkdir($fixture.'/data',0700);
foreach(['music_charts','charts_genres'] as $file)copy($project.'/lib/'.$file.'.php',$fixture.'/lib/'.$file.'.php');copy($project.'/music_charts_config.php',$fixture.'/music_charts_config.php');
file_put_contents($fixture.'/lib.php',<<<'PHP'
<?php
define('LAB_DATA',__DIR__.'/data');define('LAB_PROTECTED_ADMIN_ID','fixture');
function lab_auth_require_admin():void{}
function lab_auth_current_account():array{return ['id'=>'fixture'];}
function lab_auth_pdo():PDO{global $sfcRenderDb;return $sfcRenderDb;}
function lab_h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function lab_auth_csrf_token():string{return str_repeat('a',64);}
PHP);
file_put_contents($fixture.'/lib/user_preferences.php','<?php function lab_user_theme(string $id):string{return "dark";}');
$sfcRenderDb=new PDO('sqlite::memory:');$sfcRenderDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$_SERVER['REQUEST_METHOD']='GET';
try{
 ob_start();include $fixture.'/music_charts_config.php';$html=ob_get_clean();
 preg_match_all('~<input\b(?=[^>]*name="allowed_genres\[\]")(?=[^>]*\bchecked\b)[^>]*>~',$html,$matches);if(count($matches[0])!==count(sfc_genres()))throw new RuntimeException('Not all genre options are initially checked');
 preg_match('~<input\b(?=[^>]*name="genre_filter_enabled")[^>]*>~',$html,$master);if(!$master||str_contains($master[0],'checked'))throw new RuntimeException('Filtering should initially remain off');
 preg_match_all('~<input\b(?=[^>]*name="extra_charts\[\]")[^>]*>~',$html,$sources);if(count($sources[0])!==11)throw new RuntimeException('Missing additional rankings');foreach($sources[0] as $source)if(str_contains($source,'checked'))throw new RuntimeException('Additional source activated without selection');
 if(substr_count($html,'<form ')!==1||substr_count($html,'</form>')!==1||!str_contains($html,'Guardar y aplicar selección'))throw new RuntimeException('Settings form is incomplete');
 echo 'CHARTS_RENDER_OK: '.count($matches[0]).' genre choices, 11 optional rankings, valid initial selection'.PHP_EOL;
}finally{
 if(ob_get_level())ob_end_clean();$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($fixture);
}
