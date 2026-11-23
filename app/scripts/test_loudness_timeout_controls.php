<?php
declare(strict_types=1);require dirname(__DIR__).'/lib/loudness.php';require dirname(__DIR__).'/lib/loudness_progress.php';
function check_timeout(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach([[240,[],180],[4197.081,[],1110],[999999,[],1800],[0,[],180],[4197.081,['loudness_timeout_mode'=>'fixed','loudness_timeout_minutes'=>30],1800],[240,['loudness_timeout_mode'=>'fixed','loudness_timeout_minutes'=>120],7200],[240,['loudness_timeout_mode'=>'fixed','loudness_timeout_minutes'=>0],180],[240,['loudness_timeout_mode'=>'fixed','loudness_timeout_minutes'=>999],7200]] as [$duration,$cfg,$expected])check_timeout(sf_loudness_timeout_seconds($duration,$cfg)===$expected,'Timeout calculation');
$r=sflp_failure_reason(['error'=>'El análisis de sonoridad superó su límite de 1800 segundos.']);check_timeout($r['kind']==='timeout'&&str_contains($r['reason'],'1800')&&str_contains($r['action'],'Fijo'),'Explicit timeout guidance');
$r=sflp_failure_reason(['error'=>'No se pudo medir la sonoridad','started_at'=>'2026-11-11T00:00:00Z','finished_at'=>'2026-11-11T00:03:00Z']);check_timeout($r['kind']==='timeout_legacy'&&str_contains($r['reason'],'Probable'),'Legacy timeout identified as inference');
$r=sflp_failure_reason(['error'=>'FFmpeg no devolvió una medición válida de sonoridad (código 1).']);check_timeout($r['kind']==='measurement','Measurement failure');
$r=sflp_failure_reason(['error'=>'SQL /private/secret path token=hidden']);check_timeout(!str_contains(json_encode($r),'secret')&&!str_contains(json_encode($r),'token='),'Raw errors cannot leak');
echo "PASS automatic/fixed bounds and actionable safe timeout/measurement/legacy errors\n";
