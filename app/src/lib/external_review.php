<?php
declare(strict_types=1);
require_once __DIR__.'/external_validation.php';
function sfem_pending_review():?array{
 foreach(array_reverse(glob(sfem_dir().'/job-*.json')?:[]) as $path){$j=sfem_read_json($path);if(($j['result']??'')!=='applied'||($j['review_state']??'pending')!=='pending'||empty($j['publication']['backup']))continue;
  $current=sfem_bundle()['hashes'];if(($j['publication']['hashes']??[])!==$current){$j['review_state']='superseded';$j['stage']='Actualización sustituida por cambios posteriores';$j['reviewed_at']=gmdate('c');sfem_json($path,$j);continue;}return $j;
 }return null;
}
function sfem_review_update(string $id,string $decision,string $actor):array{
 if(!preg_match('/^\d{8}-\d{6}-[a-f0-9]{10}$/D',$id)||!in_array($decision,['approve','rollback'],true))throw new RuntimeException('Revisión o decisión inválida.');
 $lock=fopen(sfem_dir().'/review.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Otra validación del administrador está en curso.');
 try{
  $file=sfem_dir().'/job-'.$id.'.json';$job=sfem_read_json($file);
  if(($job['state']??'')!=='done'||($job['result']??'')!=='applied'||($job['review_state']??'pending')!=='pending')throw new RuntimeException('Esta actualización no está pendiente de validación.');
  $expected=$job['publication']['hashes']??[];
  if(!$expected||sfem_hashes()!==$expected)throw new RuntimeException('Los adaptadores cambiaron después de esta actualización. No se aprobarán ni sobrescribirán cambios posteriores.');
  if($decision==='rollback'){$changes=sfem_version_sources((string)$job['publication']['backup']);$job['rollback']=sfem_apply($changes,$expected,$actor,'Rollback de revisión '.$id);$job['review_state']='rolled_back';$job['stage']='Actualización revertida por el administrador';}
  else{$job['review_state']='approved';$job['stage']='Actualización validada por el administrador';}
  $job['reviewed_at']=gmdate('c');$job['reviewed_by']=$actor;sfem_json($file,$job);return $job;
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
