<?php
declare(strict_types=1);
function sf_track_storage_bytes(array $track):array {
 $root=realpath(LAB_HLS);$master=realpath(LAB_ROOT.'/'.(string)($track['hls']??''));if(!$root||!$master||!str_starts_with($master,$root.'/')||!is_file($master))throw new RuntimeException('Audio no disponible para medir');$dir=dirname($master);if($dir===$root)throw new RuntimeException('Directorio de pista inválido');
 $parts=['source'=>0,'auxiliary'=>0];$quality=[];$files=0;$bytes=0;
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));foreach($it as $f){if(++$files>100000)throw new RuntimeException('Demasiados archivos para medir');if($f->isLink()||!$f->isFile())continue;$real=$f->getRealPath();if(!$real||!str_starts_with($real,$dir.'/'))continue;$size=$f->getSize();$bytes+=$size;$rel=substr($f->getPathname(),strlen($dir)+1);$first=explode('/',$rel)[0];if(preg_match('/^([0-9]+k)(?:\.|$)/D',$first,$match)){$k=$match[1];$quality[$k]=($quality[$k]??0)+$size;}elseif(preg_match('/^source(?:\.|$)/i',$first))$parts['source']+=$size;else $parts['auxiliary']+=$size;}
 uksort($quality,static fn($a,$b)=>(int)$a<=>(int)$b);return ['total_bytes'=>$bytes,'source_bytes'=>$parts['source'],'auxiliary_bytes'=>$parts['auxiliary'],'qualities'=>$quality,'measurement'=>'Suma del tamaño de archivos, no bloques físicos reservados; una pista reutilizada comparte estos mismos archivos.'];
}
function sf_track_storage_format(int $n):string {if($n<1024)return $n.' B';if($n<1048576)return number_format($n/1024,2,'.',',').' KiB';return number_format($n/1048576,2,'.',',').' MiB';}
