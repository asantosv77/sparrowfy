<?php
declare(strict_types=1);
function sf_quality_profiles_defaults():array{return ['128'=>[64,128],'192'=>[64,128,192],'256'=>[64,128,256],'320'=>[64,192,256],'lossless'=>[64,192,256]];}
function sf_quality_profiles_normalize(array $profiles):array{
 $out=[];foreach(sf_quality_profiles_defaults() as $key=>$default){$max=$key==='lossless'?256:min(256,(int)$key);$values=$profiles[$key]??$default;if(!is_array($values))throw new InvalidArgumentException('Perfil de calidad inválido');$rates=[];foreach($values as $v){if((string)$v==='0'||$v==='')continue;$k=(int)$v;if(!in_array($k,[32,48,64,96,128,160,192,256],true)||$k>$max)throw new InvalidArgumentException('Calidad fuera del límite del origen');if(in_array($k,$rates,true))throw new InvalidArgumentException('No repitas calidades en un perfil');$rates[]=$k;}if(!in_array(64,$rates,true)||count($rates)>3)throw new InvalidArgumentException('Cada perfil requiere 64 kbps y un máximo de tres calidades');sort($rates);$out[$key]=$rates;}return $out;
}
function sf_quality_profile_rates(int $source,array $cfg,string $codec=''):array{
 $lossless=in_array(strtolower($codec),['flac','alac','wavpack','ape','tta'],true)||str_starts_with(strtolower($codec),'pcm_');
 $profiles=sf_quality_profiles_normalize((array)($cfg['quality_source_profiles']??sf_quality_profiles_defaults()));$source=$source>0?$source:128;
 $key=$lossless?'lossless':($source>=320?'320':($source>=256?'256':($source>=192?'192':'128')));
 $rates=array_values(array_filter($profiles[$key],static fn($k)=>$lossless||$k<=$source));if(!$rates)$rates=[max(32,min(64,$source))];return $rates;
}
