<?php
declare(strict_types=1);
// Only media imports belong in the album import recovery panel.
// Background maintenance (e.g. loudness) has its own lifecycle.
function sfq_album_import_jobs(PDO $db, string $albumId, string $userId, bool $admin): array {
 $sql="SELECT id, album_id, operation, label, state, stage, progress FROM sf_processing_jobs WHERE state IN ('queued','running') AND operation IN ('upload','youtube','client_hls')";
 $params=[];
 if($albumId!==''){$sql.=' AND album_id=?';$params[]=$albumId;}
 if($albumId==='' || !$admin){$sql.=' AND user_id=?';$params[]=$userId;}
 $sql.=' ORDER BY created_at ASC LIMIT 100';
 $q=$db->prepare($sql);$q->execute($params);
 return $q->fetchAll(PDO::FETCH_ASSOC);
}

function sfq_album_import_finished(PDO $db,string $albumId,string $userId,bool $admin,array $ids):array {
 $ids=array_slice(array_values(array_unique($ids)),0,100);if(!$ids)return [];
 $sql="SELECT id,album_id,operation,label,state,error FROM sf_processing_jobs WHERE state IN ('done','failed') AND operation IN ('upload','youtube','client_hls') AND id IN (".implode(',',array_fill(0,count($ids),'?')).')';$params=$ids;
 if($albumId!==''){$sql.=' AND album_id=?';$params[]=$albumId;}
 if($albumId===''||!$admin){$sql.=' AND user_id=?';$params[]=$userId;}
 $q=$db->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
}
