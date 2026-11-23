<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require_once dirname(__DIR__).'/auth.php';
require_once dirname(__DIR__).'/lib/favorite_albums.php';
try{
 $p=lab_auth_pdo();
 sf_fav_albums_schema($p);
 $exists=sf_fav_albums_table_exists($p);
 echo "favorite_albums: ".($exists?'lista':'ERROR')."\n";
 $cols=[];
 foreach($p->query('PRAGMA table_info(favorite_albums)') as $c)$cols[]=(string)($c['name']??'');
 echo "columnas: ".implode(',',$cols)."\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
