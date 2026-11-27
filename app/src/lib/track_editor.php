<?php
declare(strict_types=1);
const SF_EDITOR_METADATA_LIMITS = ['name'=>160,'title'=>160,'artist'=>160,'album'=>160,'genre'=>100,'year'=>4];

function sf_editor_metadata_changed(array $post, array $track): bool {
 foreach ([...array_keys(SF_EDITOR_METADATA_LIMITS), 'artwork'] as $key) {
  if (array_key_exists($key, $post) &&
      (!is_string($post[$key]) || trim($post[$key]) !== trim((string)($track[$key] ?? '')))) {
   return true;
  }
 }
 return false;
}

/** Validate all metadata before mutating a catalogue entry. Audio/references are preserved. */
function sf_editor_metadata(array $post,array $track): array {
 foreach(SF_EDITOR_METADATA_LIMITS as $key=>$limit){
  $value=trim((string)($post[$key]??($track[$key]??'')));
  if(mb_strlen($value)>$limit||preg_match('/[\x00-\x1F\x7F]/u',$value))throw new RuntimeException('Revisa el campo '.$key.'.');
  $track[$key]=$value;
 }
 if($track['name']==='')throw new RuntimeException('Indica el nombre visible de la pista.');
 if($track['year']!==''&&!preg_match('/^[0-9]{1,4}$/D',$track['year']))throw new RuntimeException('El año debe tener hasta cuatro dígitos.');
 $cover=trim((string)($post['artwork']??($track['artwork']??'')));
 if($cover!==''&&!sf_editor_local_cover($cover)){
  $u=parse_url($cover);
  if(!is_array($u)||($u['scheme']??'')!=='https'||isset($u['user'])||isset($u['pass'])||isset($u['port'])||!preg_match('/(^|\.)mzstatic\.com$/i',(string)($u['host']??'')))throw new RuntimeException('Selecciona una portada de los resultados de búsqueda.');
 }
 $track['artwork']=$cover;return $track;
}

/** Only immutable, locally stored images are accepted as local artwork. */
function sf_editor_local_cover(string $url):bool {
 if(!preg_match('/^artwork_image\.php\?key=([a-f0-9]{64})$/D',$url,$m))return false;
 $file=LAB_DATA.'/artwork-images/'.$m[1].'.img';return is_file($file)&&!is_link($file);
}
function sf_editor_cover_info(string $file):array {
 $size=filesize($file);if(!$size||$size>8*1024*1024)throw new RuntimeException('La portada debe pesar como máximo 8 MiB.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);$info=@getimagesize($file);
 if(!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)||!$info||($info['mime']??'')!==$mime)throw new RuntimeException('Selecciona una imagen JPG, PNG, WebP o GIF válida.');
 if($info[0]<1||$info[1]<1||$info[0]>8192||$info[1]>8192||$info[0]*$info[1]>32000000)throw new RuntimeException('La imagen es demasiado grande en resolución.');
 return ['mime'=>$mime,'width'=>$info[0],'height'=>$info[1]];
}
/** Normalize uploaded artwork before hashing/publishing; originals are not retained. */
function sf_editor_convert_cover(string $source,string $dest):void {
 sf_editor_cover_info($source);
 if(!class_exists('Imagick')||!Imagick::queryFormats('WEBP'))throw new RuntimeException('El servidor no puede convertir la portada a WebP.');
 $image=new Imagick();
 try{
  $image->readImage($source.'[0]');
  switch($image->getImageOrientation()){
   case Imagick::ORIENTATION_TOPRIGHT:$image->flopImage();break;
   case Imagick::ORIENTATION_BOTTOMRIGHT:$image->rotateImage('none',180);break;
   case Imagick::ORIENTATION_BOTTOMLEFT:$image->flipImage();break;
   case Imagick::ORIENTATION_LEFTTOP:$image->transposeImage();break;
   case Imagick::ORIENTATION_RIGHTTOP:$image->rotateImage('none',90);break;
   case Imagick::ORIENTATION_RIGHTBOTTOM:$image->transverseImage();break;
   case Imagick::ORIENTATION_LEFTBOTTOM:$image->rotateImage('none',-90);break;
  }
  $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
  if($image->getImageColorspace()!==Imagick::COLORSPACE_SRGB)$image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
  if($image->getImageWidth()>600||$image->getImageHeight()>600)$image->thumbnailImage(600,600,true);
  $image->stripImage();$image->setImagePage(0,0,0,0);
  $image->setImageFormat('webp');$image->setImageCompressionQuality(82);
  if(!$image->writeImage($dest))throw new RuntimeException('No se pudo convertir la portada.');
  $info=sf_editor_cover_info($dest);
  if($info['mime']!=='image/webp'||$info['width']>600||$info['height']>600)throw new RuntimeException('La portada convertida no es válida.');
 }catch(ImagickException $e){throw new RuntimeException('No se pudo procesar la imagen. Elige otra portada.',0,$e);}
 finally{$image->clear();$image->destroy();}
}
function sf_editor_store_cover(array $upload):string {
 if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($upload['tmp_name']??'')))throw new RuntimeException('No se pudo recibir la imagen. Elige el archivo de nuevo.');
 $tmp=(string)$upload['tmp_name'];sf_editor_cover_info($tmp);$dir=LAB_DATA.'/artwork-images';
 if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear el directorio de portadas.');
 if(!is_file($dir.'/.htaccess'))file_put_contents($dir.'/.htaccess',"Require all denied\n",LOCK_EX);
 $stage=tempnam($dir,'.cover-');if($stage===false)throw new RuntimeException('No se pudo preparar la portada.');
 try{
  sf_editor_convert_cover($tmp,$stage);$key=hash_file('sha256',$stage);$dest=$dir.'/'.$key.'.img';
  if(is_link($dest))throw new RuntimeException('Destino de portada inválido.');
  if(!is_file($dest)){chmod($stage,0660);if(!rename($stage,$dest))throw new RuntimeException('No se pudo publicar la imagen.');}
 }finally{if(is_file($stage))@unlink($stage);}
 return 'artwork_image.php?key='.$key;
}
