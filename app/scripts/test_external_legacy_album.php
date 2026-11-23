<?php
$root=dirname(__DIR__);$fixture=$root.'/data/external-ai/legacy-album-'.bin2hex(random_bytes(6));mkdir($fixture.'/lib',0700,true);
define('SFEM_TEST_ROOT',$fixture);require $root.'/lib/admin_external.php';require $root.'/lib/external_validation.php';
foreach(array_merge(sfem_files(),['lib/acoustid_health.php','youtube_url_normalizer.php']) as $f)copy($root.'/'.$f,$fixture.'/'.$f);
try{
 $id=sfem_snapshot('fixture','legacy snapshot test');$dir=sfem_versions_dir().'/'.$id;$m=sfem_read_json($dir.'/manifest.json');unset($m['hashes']['lib/external_album.php']);sfem_json($dir.'/manifest.json',$m);unlink($dir.'/external_album.php');
 $before=sfem_sources();$old=sfem_version_sources($id);if($old['lib/external_album.php']!==$before['lib/external_album.php'])throw new RuntimeException('Legacy restore lost the new adapter');
 sfem_validate($old);echo "PASS legacy four-adapter snapshot preserves fifth adapter and passes validation\n";
 $changed=str_replace('Proveedor no permitido','Changed transport',$before['lib/external_album.php']);try{sfem_static_check('lib/external_album.php',$before['lib/external_album.php'],$changed);throw new LogicException('Protected transport accepted');}catch(RuntimeException $e){echo "PASS album transport cannot be changed by maintenance AI\n";}
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $p)$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());rmdir($fixture);}
