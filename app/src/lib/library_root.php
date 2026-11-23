<?php
declare(strict_types=1);
const SF_LOOSE_ID='fffffffffffffffe';
function sf_is_loose(array $a):bool{return ($a['id']??'')===SF_LOOSE_ID;}
function sf_ensure_loose():array{
 $lock=fopen(LAB_DATA.'/.library-root.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Biblioteca ocupada');
 try{
 $a=lab_load_album(SF_LOOSE_ID);
 if(!$a){$a=['id'=>SF_LOOSE_ID,'title'=>'Pistas sueltas','artist'=>'','created_at'=>gmdate('c'),'library_root'=>true,'tracks'=>[]];lab_save_album($a);}
 if(empty($a['library_root']))throw new RuntimeException('El identificador reservado ya esta ocupado');
 $q=lab_auth_pdo()->prepare('SELECT id FROM albums WHERE id=?');$q->execute([SF_LOOSE_ID]);
 if(!$q->fetchColumn()){require_once __DIR__.'/sqlite_catalog_sync.php';lab_sqlite_sync_album($a);}
 return $a;
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
