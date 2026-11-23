<?php
declare(strict_types=1);
require_once __DIR__.'/media_log.php';
require_once __DIR__.'/favorite_albums.php';

/** Called under sf_reuse_lock. Hold every affected album lock until commit/rollback. */
function sf_reuse_delete_prepare(string $id,array $album):array {
 if($id==='fffffffffffffffe')throw new RuntimeException('Las pistas sueltas se gestionan individualmente');
 $db=sf_reuse_db();$targets=[$id=>$album];
 $q=$db->prepare('SELECT DISTINCT album_id FROM sf_album_track_refs WHERE source_album_id=?');$q->execute([$id]);
 foreach($q->fetchAll(PDO::FETCH_COLUMN) as $target){$a=lab_load_album($target);if($a)$targets[$target]=$a;}
 if(($album['album_type']??'')!=='online_links'){
  // Prepare the fallback before the writer transaction, including concurrent favorites.
  require_once __DIR__.'/library_root.php';$a=sf_ensure_loose();$targets[$a['id']]=$a;
 }
 ksort($targets);$locks=[];$snapshots=[];
 try{
  foreach($targets as $target=>$a){$file=LAB_ALBUMS.'/'.$target.'/.processing-append.lock';$f=fopen($file,'c');if(!$f)throw new RuntimeException('No se pudo bloquear la colección');if(!flock($f,LOCK_EX|LOCK_NB)){fclose($f);throw new RuntimeException('Colección ocupada. Intenta de nuevo');}$locks[]=$f;}
  foreach($targets as $target=>$a){$a=lab_load_album((string)$target);if(!$a)throw new RuntimeException('La colección cambió durante el borrado');$targets[$target]=$a;$file=LAB_ALBUMS.'/'.$target.'/album.json';$raw=file_get_contents($file);if(!is_string($raw))throw new RuntimeException('No se pudo respaldar el catálogo');$snapshots[$file]=$raw;hml_delete_preflight_tree(dirname($file));}
  $dir=LAB_DATA.'/deletion-recovery';if(!is_dir($dir)&&!mkdir($dir,0770,true))throw new RuntimeException('No se pudo crear el diario de recuperación');
  if(file_put_contents($dir.'/.htaccess',"Require all denied\n")===false)throw new RuntimeException('No se pudo proteger el diario');
  $batch=['album_id'=>$id,'albums'=>$targets,'locks'=>$locks,'snapshots'=>$snapshots,'moves'=>[],'journal'=>$dir.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8)).'.json'];
  sf_reuse_delete_checkpoint($batch,'prepared');return $batch;
 }catch(Throwable $e){foreach(array_reverse($locks) as $f){flock($f,LOCK_UN);fclose($f);}throw $e;}
}
/** Recovery journal is written before any media rename; SQLite itself rolls back on process exit. */
function sf_reuse_delete_checkpoint(array $batch,string $state):void {
 $file=$batch['journal'];$tmp=$file.'.tmp';
 $json=json_encode(['state'=>$state,'updated_at'=>gmdate('c'),'snapshots'=>$batch['snapshots'],'moves'=>$batch['moves'],'quarantines'=>$batch['quarantines']??[]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 if(file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$file))throw new RuntimeException('No se pudo guardar el diario de recuperación');
 sf_media_event('deletion.state',['album_id'=>$batch['album_id']??'','state'=>$state,'journal_id'=>basename($file)]);
}
function sf_reuse_delete_restore(array $batch):array {
 $errors=[];
 foreach(array_reverse($batch['moves']) as [$old,$new])if(is_dir($new)&&!@rename($new,$old))$errors[]='No se pudo restaurar '.$old;
 foreach($batch['snapshots'] as $file=>$raw){
  if(is_file($file)&&file_get_contents($file)===$raw)continue;
  $tmp=$file.'.restore-'.bin2hex(random_bytes(6));
  if(file_put_contents($tmp,$raw,LOCK_EX)===false||!@rename($tmp,$file)){$errors[]='No se pudo restaurar '.$file;@unlink($tmp);}
 }
 return $errors;
}

function sf_delete_album_with_preservation(string $albumId,array $album):?string {
    $batch=null;$pdo=null;$quarantines=[];
    sf_media_event('deletion.started',['album_id'=>$albumId]);
    try{
        $batch=sf_reuse_delete_prepare($albumId,$album);
        $album=$batch['albums'][$albumId];
        // PRE-FLIGHT del álbum completo: no borrar si cualquier parte no puede apartarse/eliminarse.
        $albumDir = LAB_ALBUMS . '/' . $albumId;
        $albumFile = $albumDir . '/album.json';
        if (!is_file($albumFile) || !is_readable($albumFile)) {
            throw new RuntimeException('album.json no existe o no se puede leer. No se eliminó nada.');
        }
        hml_delete_preflight_tree($albumDir);

        $hlsCandidates = glob(rtrim(LAB_HLS, '/') . '/' . $albumId . '_*', GLOB_ONLYDIR) ?: [];
        if (count($hlsCandidates) > 1) {
            throw new RuntimeException('Hay varias carpetas HLS para este álbum. Audítalas antes de eliminarlo. No se eliminó nada.');
        }
        $hlsDir = $hlsCandidates[0] ?? null;
        if ($hlsDir !== null) {
            hml_delete_preflight_tree($hlsDir);
            $realHlsRoot = realpath(LAB_HLS);
            $realHlsDir = realpath($hlsDir);
            if ($realHlsRoot === false || $realHlsDir === false
                || dirname($realHlsDir) !== $realHlsRoot
                || !str_starts_with(basename($realHlsDir), $albumId . '_')) {
                throw new RuntimeException('No se pudo validar la carpeta HLS del álbum. No se eliminó nada.');
            }
        }
        $pdo = hml_delete_sqlite_open_and_validate($albumId, $album);
        if(!$pdo instanceof PDO)throw new RuntimeException('Se requiere SQLite para borrar la colección con seguridad');
        $batch['db']=$pdo;
        sf_reuse_preserve_before_delete($albumId,'',$batch);
        $album=$batch['albums'][$albumId];

        try {
            $albumTrash = hml_delete_quarantine($albumDir);
            $quarantines[] = ['original' => $albumDir, 'quarantine' => $albumTrash];
            $batch['quarantines']=$quarantines;sf_reuse_delete_checkpoint($batch,'quarantined');
            if ($hlsDir !== null) {
                $hlsTrash = hml_delete_quarantine($hlsDir);
                $quarantines[] = ['original' => $hlsDir, 'quarantine' => $hlsTrash];
                $batch['quarantines']=$quarantines;sf_reuse_delete_checkpoint($batch,'quarantined');
            }

            if ($pdo instanceof PDO) {
                $delRenditions = $pdo->prepare('DELETE FROM track_renditions WHERE track_id IN (SELECT id FROM tracks WHERE album_id = ?)');
                $delRenditions->execute([$albumId]);
                $delTracks = $pdo->prepare('DELETE FROM tracks WHERE album_id = ?');
                $delTracks->execute([$albumId]);
                $expectedTracks = count($album['tracks'] ?? []);
                if ($delTracks->rowCount() !== $expectedTracks) {
                    throw new RuntimeException('SQLite no eliminó la cantidad esperada de pistas; se restaurará el estado anterior.');
                }
                $delAlbum = $pdo->prepare('DELETE FROM albums WHERE id = ?');
                $delAlbum->execute([$albumId]);
                if ($delAlbum->rowCount() !== 1) {
                    throw new RuntimeException('SQLite no eliminó exactamente un álbum; se restaurará el estado anterior.');
                }
                $pdo->prepare('DELETE FROM sf_album_track_refs WHERE album_id=?')->execute([$albumId]);
                // La colección desaparece de los favoritos de todos sus usuarios.
                sf_fav_albums_remove($pdo, $albumId);
                $pdo->commit();
                try{sf_reuse_delete_checkpoint($batch,'committed');}catch(Throwable $logError){error_log('Album deletion journal: '.$logError->getMessage());}
            }
        } catch (Throwable $e) { throw $e; }

        $warning = null;
        foreach ($quarantines as $q) {
            try { hml_delete_tree($q['quarantine']);sf_media_event('deletion.quarantine_removed',['album_id'=>$albumId,'path'=>$q['original']]); }
            catch (Throwable $cleanupError) {
                $warning = 'El álbum se quitó del catálogo, pero quedó contenido en cuarentena para limpieza manual: ' . $q['quarantine'] . ' (' . $cleanupError->getMessage() . ')';
                break;
            }
        }
        if (!($pdo instanceof PDO)) {
            $warning = trim(($warning ? $warning . ' ' : '') . 'SQLite no está configurada; solo se eliminaron archivos JSON/HLS (modo legado).');
        }
        try {
            require_once __DIR__.'/album_deletion.php';
            $acct = lab_auth_current_account();
            sf_delreq_resolve($albumId, 'deleted', (string)($acct['id'] ?? ''), (string)($acct['username'] ?? ''));
        } catch (Throwable $ignored) {}
        sf_media_event('deletion.completed',['album_id'=>$albumId,'reason'=>$warning===null?'complete':'quarantine_retained']);
        return $warning;
    }catch(Throwable $e){
        $errors=hml_delete_rollback($pdo,$quarantines,null);
        if($batch!==null){$errors=array_merge($errors,sf_reuse_delete_restore($batch));try{sf_reuse_delete_checkpoint($batch,$errors?'recovery_required':'rolled_back');}catch(Throwable $ignored){}}
        sf_media_event('deletion.failed',['album_id'=>$albumId,'remaining'=>count($errors),'reason'=>$errors?'recovery_required':'rolled_back'],$e);
        throw new RuntimeException($e->getMessage().($errors?' Revisión manual: '.implode('; ',$errors):' Se restauró el estado anterior.'),0,$e);
    }finally{
        if($batch!==null)foreach(array_reverse($batch['locks']) as $f){flock($f,LOCK_UN);fclose($f);}
    }
}
