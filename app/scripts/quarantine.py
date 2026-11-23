"""Admin-only maintenance actions; no paths supplied by the browser are trusted."""
import pathlib,json,os,hashlib,shutil,time,uuid,fcntl

def sha(p):
 h=hashlib.sha256()
 with p.open('rb') as f:
  for b in iter(lambda:f.read(1048576),b''):h.update(b)
 return h.hexdigest()
def inventory(folder):
 result={}
 for p in folder.rglob('*'):
  if p.is_symlink():raise RuntimeError('No se admiten enlaces en cuarentena')
  if p.is_file():result[str(p.relative_to(folder))]=sha(p)
 return result

def run(m,action,item,confirmed=False):
 base=m.BASE/'quarantine';base.mkdir(exist_ok=True);index=base/'index.json'
 records=json.loads(index.read_text()) if index.exists() else []
 # Import the manually verified quarantine without moving any audio again.
 known={r['folder'] for r in records}
 for f in base.glob('*/locations.json'):
  for old in json.loads(f.read_text()):
   folder=pathlib.Path(old['quarantine']).resolve()
   if not folder.is_relative_to(base.resolve()) or str(folder) in known or not folder.is_dir():continue
   source=pathlib.Path(old['original']).resolve()
   if not source.is_relative_to(m.MEDIA):continue
   records.append({'id':uuid.uuid4().hex,'folder':str(folder),'original':str(source.relative_to(m.MEDIA)),'state':'quarantined','created':time.time(),'files':inventory(folder)})
   known.add(str(folder))
 m.write_json(index,records)
 if action=='quarantine_list':return records
 lock=open(m.ROOT/'data/.track-reuse.lock','a');fcntl.flock(lock,fcntl.LOCK_EX)
 try:
  if action=='quarantine_output':
   import abandoned_outputs
   rel=pathlib.PurePosixPath(item)
   if len(rel.parts)!=2 or '..' in rel.parts:raise RuntimeError('Invalid output path')
   source=m.MEDIA.joinpath(*rel.parts);meta,reason=abandoned_outputs.eligible(m,source,'hls_output')
   if reason or not abandoned_outputs.verified_files(m,source):raise RuntimeError('Output changed or is not eligible')
   original=str(source.relative_to(m.MEDIA))
  elif action=='quarantine':
   report=m.audit();valid={i['path'] for i in report['issues'] if i['kind']=='possible_orphan_media'}|{i['path'] for i in report.get('retained_media',[])}
   if item not in valid:
     prior=[r for r in records if r.get('original')+'/master.m3u8'==item and r.get('state')=='quarantined']
     if len(prior)==1 and pathlib.Path(prior[0]['folder']).is_dir() and not (m.MEDIA/prior[0]['original']).exists():return 'El audio ya estaba en cuarentena; no se repitió el traslado'
     raise RuntimeError('El hallazgo cambió o ya no es un archivo huérfano')
   rel=pathlib.PurePosixPath(item)
   if len(rel.parts)!=3 or rel.name!='master.m3u8' or '..' in rel.parts:raise RuntimeError('Ruta inválida')
   source=m.MEDIA.joinpath(*rel.parts).parent
   original=str(source.relative_to(m.MEDIA))
  else:
   matches=[r for r in records if r['id']==item]
   if len(matches)!=1:raise RuntimeError('Elemento no disponible en cuarentena')
   prior=matches[0]
   if action=='quarantine_delete' and prior['state']=='deleted' and not pathlib.Path(prior['folder']).exists():
    if not confirmed:raise RuntimeError('Se requiere confirmación para eliminar')
    return 'La copia ya estaba eliminada; no se repitió la operación'
   if action=='quarantine_restore' and prior['state']=='restored' and (m.MEDIA/prior['original']).is_dir():return 'La copia ya se había restaurado; no se repitió la operación'
   if prior['state']!='quarantined':raise RuntimeError('El estado del elemento cambió; actualiza la lista de cuarentena')
   record=matches[0];original=record['original'];source=m.MEDIA/original
  if source.is_symlink() or source.resolve()!=source:raise RuntimeError('No se permiten rutas enlazadas')
  source=source.resolve()
  if not source.is_relative_to(m.MEDIA) or source==m.MEDIA:raise RuntimeError('Ruta fuera del audio')
  with m.dbopen() as db:
   relpath='hls_out/'+original+'/';job=source.name
   if db.execute('SELECT COUNT(*) FROM tracks WHERE hls_path LIKE ? OR job_id=?',(relpath+'%',job)).fetchone()[0]:raise RuntimeError('La carpeta tiene referencias activas; operación bloqueada')
   aid=source.parent.name[:16]
   for table in ['audio_edit_jobs','sf_processing_jobs']:
    if db.execute('SELECT COUNT(*) FROM '+table+" WHERE album_id=? AND state IN ('queued','running')",(aid,)).fetchone()[0]:raise RuntimeError('Hay procesamiento activo en la colección')
  if action in ['quarantine','quarantine_output']:
   if not os.access(source.parent,os.W_OK|os.X_OK) or any(not os.access(p,os.W_OK|os.X_OK) for p in [source,*[x for x in source.rglob('*') if x.is_dir()]]):raise RuntimeError('Los permisos del audio impiden trasladarlo; no se modificó')
   files=inventory(source);rid=uuid.uuid4().hex;target=base/'items'/rid;target.parent.mkdir(exist_ok=True)
   shutil.copytree(source,target,copy_function=shutil.copyfile)
   if inventory(target)!=files:raise RuntimeError('No coincide la copia de cuarentena; se conserva el original')
   record={'id':rid,'folder':str(target),'original':original,'state':'quarantined','created':time.time(),'files':files}
   for p in [target,*target.rglob('*')]:os.chmod(p,0o2770 if p.is_dir() else 0o660)
   record['state']='copied';records.append(record);m.write_json(index,records)
   shutil.rmtree(source);record['state']='quarantined';message='Audio enviado a cuarentena'
  else:
   target=pathlib.Path(record['folder'])
   if not target.resolve().is_relative_to(base.resolve()) or target.is_symlink():raise RuntimeError('Cuarentena inválida')
   if inventory(target)!=record['files']:raise RuntimeError('La cuarentena cambió; requiere revisión')
   if action=='quarantine_restore':
    if source.exists():raise RuntimeError('La ruta original ya existe; no se sobrescribirá')
    if not source.parent.is_dir():raise RuntimeError('La colección original ya no existe')
    shutil.copytree(target,source,copy_function=shutil.copyfile)
    if inventory(source)!=record['files']:raise RuntimeError('La restauración no coincide; se conserva la cuarentena')
    shutil.rmtree(target)
    record['state']='restored';message='Audio devuelto a su ubicación; no se añadió al catálogo'
   elif action=='quarantine_delete':
    if not confirmed:raise RuntimeError('Debes confirmar que probaste las pistas y solicitar la eliminación definitiva')
    shutil.rmtree(target);record['state']='deleted';message='Copia en cuarentena eliminada definitivamente'
   else:raise RuntimeError('Acción inválida')
  record['updated']=time.time();m.write_json(index,records)
  with (base/'events.jsonl').open('a') as log:log.write(json.dumps({'time':time.time(),'action':action,'id':record['id'],'original':original})+'\n')
  m.write_json(m.BASE/'latest-audit.json',m.audit());return message
 finally:fcntl.flock(lock,fcntl.LOCK_UN);lock.close()
