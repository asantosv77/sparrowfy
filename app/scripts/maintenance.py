#!/usr/bin/env python3
"""Sparrow-Fy: private incremental snapshots, isolated restore and read-only audit."""
import argparse,datetime,fcntl,hashlib,json,os,pathlib,shutil,sqlite3,time,stat
ROOT=pathlib.Path(__file__).resolve().parent.parent
SETTINGS=ROOT/'data/maintenance-settings.json'
STATE=ROOT/'data/maintenance-state.json'
BASE=ROOT/'backups'
SECONDARY=None
DB=pathlib.Path(os.environ.get('HLS_MUSIC_LAB_SQLITE_DB','/sparrowfy/database/library.sqlite'))
MEDIA=(ROOT/'hls_out').resolve()
def digest(path):
 h=hashlib.sha256()
 with open(path,'rb') as f:
  for block in iter(lambda:f.read(1024*1024),b''):h.update(block)
 return h.hexdigest()
def write_json(path,value):
 tmp=path.with_suffix(path.suffix+'.tmp');tmp.write_text(json.dumps(value,ensure_ascii=False,indent=2));tmp.replace(path)
def dbopen(path=DB):return sqlite3.connect('file:'+str(path)+'?mode=ro',uri=True,timeout=30)
def catalogue(db):
 return hashlib.sha256(repr([db.execute('SELECT * FROM '+t+' ORDER BY id').fetchall() for t in ('albums','tracks')]).encode()).hexdigest()
def audit(dbpath=DB,media=MEDIA):
 issues=[];retained=[]
 with dbopen(dbpath) as db:
  integrity=[r[0] for r in db.execute('PRAGMA integrity_check')]
  if integrity!=['ok']:issues.append({'kind':'sqlite_integrity','detail':integrity})
  for row in db.execute('PRAGMA foreign_key_check'):issues.append({'kind':'foreign_key','table':row[0],'row':row[1]})
  tracks=db.execute('SELECT id,album_id,hls_path FROM tracks').fetchall();known=set()
  for tid,aid,rel in tracks:
   if not rel.startswith('hls_out/') or '..' in pathlib.PurePosixPath(rel).parts:
    issues.append({'kind':'unsafe_media_path','track':tid});continue
   file=media/rel[len('hls_out/'):];known.add(file.parent.resolve())
   if not file.is_file():issues.append({'kind':'missing_manifest','track':tid,'path':rel});continue
   pending=[file];seen=set()
   while pending:
    playlist=pending.pop()
    if playlist in seen:continue
    seen.add(playlist)
    try:lines=playlist.read_text().splitlines()
    except (OSError,UnicodeError):issues.append({'kind':'unreadable_playlist','track':tid});continue
    for line in lines:
     line=line.strip()
     if not line or line.startswith('#'):continue
     target=(playlist.parent/line).resolve()
     if not target.is_relative_to(media.resolve()):issues.append({'kind':'external_playlist_reference','track':tid});continue
     if not target.is_file():issues.append({'kind':'missing_segment','track':tid,'path':str(target.relative_to(media))})
     elif target.suffix=='.m3u8':pending.append(target)
  tables={r[0] for r in db.execute("SELECT name FROM sqlite_master WHERE type='table'")}
  if 'sf_album_track_refs' in tables:
   for aid,tid in db.execute('SELECT r.album_id,r.track_id FROM sf_album_track_refs r LEFT JOIN albums a ON a.id=r.album_id LEFT JOIN tracks t ON t.id=r.track_id WHERE a.id IS NULL OR t.id IS NULL OR t.album_id<>r.source_album_id'):
    issues.append({'kind':'broken_collection_reference','album':aid,'track':tid})
  for table in ['favorites','playlist_tracks','recent_tracks']:
   if table in tables:
    n=db.execute('SELECT COUNT(*) FROM '+table+' r LEFT JOIN tracks t ON t.id=r.track_id WHERE t.id IS NULL').fetchone()[0]
    if n:issues.append({'kind':'broken_track_references','table':table,'count':n})
  # Replacement receipts explicitly retain the former source for recovery.
  # Only classify it as a previous version if the same track has valid current media.
  previous={};current={tid:rel for tid,aid,rel in tracks}
  for receipt in (ROOT/'backups/replacements').glob('*.json'):
   try:
    data=json.loads(receipt.read_text());old=data['track'];rel=old['hls'];now=current.get(old['id'],'')
    if rel.startswith('hls_out/') and now.startswith('hls_out/') and now!=rel and (media/now[len('hls_out/'):]).is_file():
     previous[rel[len('hls_out/'):]]={'track':old['id'],'replacement_job':receipt.stem}
   except (OSError,ValueError,KeyError,TypeError):continue
  for f in media.rglob('master.m3u8'):
   if f.parent.resolve() in known:continue
   path=str(f.relative_to(media))
   if path in previous:retained.append({'kind':'retained_replacement_media','path':path,**previous[path]})
   else:issues.append({'kind':'possible_orphan_media','path':path})
 return {'created_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'tracks':len(tracks),'issues':issues,'retained_media':retained,'destructive_actions':False}
def source_files():
 for f in ROOT.iterdir():
  if f.is_file() and (f.suffix in ['.php','.js','.css','.webmanifest','.svg','.md','.yaml','.txt'] or f.name=='.htaccess'):yield f,'app/'+f.name
 for folder in ['assets','lib','scripts','data/albums']:
  for f in (ROOT/folder).rglob('*'):
   if f.is_symlink():raise RuntimeError('Unexpected symlink: '+str(f))
   if f.is_file():yield f,'app/'+str(f.relative_to(ROOT))
 for name in ['config.json','.htaccess','sqlite-catalog.enabled','maintenance-settings.json']:
  f=ROOT/'data'/name
  if f.is_file():yield f,'app/data/'+name
 for f in MEDIA.rglob('*'):
  if f.is_symlink():raise RuntimeError('Unexpected media symlink')
  if f.is_file():yield f,'media/'+str(f.relative_to(MEDIA))
def store(file):
 before=file.stat();h=digest(file);after=file.stat()
 if (before.st_size,before.st_mtime_ns)!=(after.st_size,after.st_mtime_ns):raise RuntimeError('File changed during backup')
 obj=BASE/'objects'/h
 if not obj.exists():
  tmp=obj.with_suffix('.tmp');shutil.copyfile(file,tmp)
  if digest(tmp)!=h:tmp.unlink();raise RuntimeError('Copy checksum mismatch')
  tmp.replace(obj)
 return {'sha256':h,'size':after.st_size}
def backup():
 stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ');stage=BASE/('pending-'+stamp);stage.mkdir()
 try:
  snap=stage/'library.sqlite'
  with dbopen() as src,sqlite3.connect(snap) as dst:src.backup(dst);version=catalogue(dst)
  report=audit(snap);write_json(stage/'audit.json',report)
  entries={'database/library.sqlite':store(snap)}
  for file,name in source_files():entries[name]=store(file)
  with dbopen() as db:
   if catalogue(db)!=version:raise RuntimeError('Catalog changed; retry next scheduled run')
  write_json(stage/'manifest.json',{'version':1,'created_at':stamp,'files':entries,'audit_issue_count':len(report['issues'])})
  snap.unlink();final=BASE/'snapshots'/stamp;stage.replace(final)
  verify_restore(final)
  write_json(BASE/'last-success.json',{'snapshot':stamp,'time':time.time(),'restore_verified':True,'audit_issues':len(report['issues'])})
  # Keep seven verified daily snapshots; media objects are shared, never deleted automatically.
  snapshots=sorted((BASE/'snapshots').iterdir())
  for old in snapshots[:-7]:
   if old.is_dir() and old.parent==BASE/'snapshots':shutil.rmtree(old)
  reachable=set()
  for m in (BASE/'snapshots').glob('*/manifest.json'):
   reachable.update(e['sha256'] for e in json.loads(m.read_text())['files'].values())
  for obj in (BASE/'objects').iterdir():
   if len(obj.name)==64 and all(c in '0123456789abcdef' for c in obj.name) and obj.name not in reachable:obj.unlink()
  return stamp
 except Exception:
  if stage.exists():shutil.rmtree(stage)
  raise
def verify_restore(snapshot,keep=False):
 manifest=json.loads((snapshot/'manifest.json').read_text());target=BASE/'restore-tests'/snapshot.name
 if target.exists():raise RuntimeError('Restore destination already exists')
 target.mkdir(parents=True)
 try:
  for name,entry in manifest['files'].items():
   dest=target/name
   if not dest.resolve().is_relative_to(target.resolve()):raise RuntimeError('Unsafe restore path')
   obj=BASE/'objects'/entry['sha256'];dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(obj,dest)
   if dest.stat().st_size!=entry['size'] or digest(dest)!=entry['sha256']:raise RuntimeError('Restore checksum mismatch')
  report=audit(target/'database/library.sqlite',target/'media')
  result={'snapshot':snapshot.name,'restored_files':len(manifest['files']),'checksums':'all verified','sqlite_integrity':'ok' if not any(i['kind']=='sqlite_integrity' for i in report['issues']) else 'failed','audit':report,'production_modified':False}
  if result['sqlite_integrity']!='ok':raise RuntimeError('Restored database integrity failure')
  write_json(snapshot/'restore-check.json',result)
  if keep:
   destination=BASE/'restored'/(snapshot.name+'-'+str(int(time.time())))
   destination.parent.mkdir(exist_ok=True);target.replace(destination);result['destination']=str(destination)
  return result
 finally:
  if target.exists() and target.parent==BASE/'restore-tests':shutil.rmtree(target)
def configured_path(value):
 if value.startswith('/home/pi/docker/vaultwarden/www/audio/'):
  value=str(ROOT/value[len('/home/pi/docker/vaultwarden/www/audio/'):])
 raw=pathlib.Path(value)
 if not raw.is_absolute():raise RuntimeError('La ruta debe ser absoluta')
 p=raw.resolve()
 if not p.is_dir() or not os.access(p,os.W_OK|os.X_OK):raise RuntimeError('La carpeta debe existir y permitir escritura a www-data')
 if p!=ROOT/'backups' and (p.is_relative_to(ROOT.parent) or ROOT.is_relative_to(p) or p.is_relative_to(MEDIA) or MEDIA.is_relative_to(p) or DB.resolve().is_relative_to(p)):
  raise RuntimeError('Usa una carpeta dedicada, separada del proyecto, base de datos y audio')
 if str(p).startswith(('/etc/','/proc/','/sys/','/dev/','/usr/','/var/lib/')) or str(p) in ['/etc','/proc','/sys','/dev','/usr','/var','/tmp','/home','/mnt']:
  raise RuntimeError('Destino del sistema no permitido')
 st=p.stat()
 if st.st_gid!=33 or not st.st_mode & stat.S_ISGID or st.st_mode & 0o007:
  raise RuntimeError('La carpeta requiere grupo www-data, setgid y sin permisos para otros (2770)')
 if any(p.iterdir()) and not (p/'.sparrowfy-backup').exists() and not (p/'snapshots').is_dir():
  raise RuntimeError('La carpeta no está vacía ni es un repositorio de respaldos Sparrow-Fy')
 return p

def mirror():
 if not SECONDARY:return
 configured_path(str(SECONDARY))
 (SECONDARY/'.sparrowfy-backup').write_text('Sparrow-Fy backup repository\n')
 (SECONDARY/'.htaccess').write_text('Require all denied\n')
 # Append-only second repository: never delete older remote copies automatically.
 for folder in ['objects','snapshots']:
  for src in (BASE/folder).rglob('*'):
   if not src.is_file():continue
   rel=src.relative_to(BASE);dst=SECONDARY/rel;dst.parent.mkdir(parents=True,exist_ok=True)
   if dst.exists() and dst.stat().st_size==src.stat().st_size and digest(dst)==digest(src):continue
   tmp=dst.with_suffix(dst.suffix+'.copying');shutil.copyfile(src,tmp)
   if digest(tmp)!=digest(src):raise RuntimeError('Checksum de segunda copia incorrecto')
   tmp.replace(dst)
 write_json(BASE/'secondary-status.json',{'state':'completed','time':time.time(),'path':str(SECONDARY)})

def main():
 global BASE,SECONDARY
 os.umask(0o007)
 parser=argparse.ArgumentParser();parser.add_argument('action',choices=['backup','audit','verify','restore','auto','configure','quarantine','quarantine_restore','quarantine_delete','quarantine_list']);parser.add_argument('--snapshot');parser.add_argument('--item',default='');parser.add_argument('--confirmed',action='store_true');parser.add_argument('--primary');parser.add_argument('--secondary',default='');args=parser.parse_args()
 lockfile=ROOT/'data/.maintenance-global.lock'
 with open(lockfile,'a') as lock:
  try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:raise RuntimeError('Ya hay una tarea de mantenimiento en curso')
  if args.action=='configure':
   primary=configured_path(args.primary or str(ROOT/'backups'));secondary=configured_path(args.secondary) if args.secondary.strip() else None
   if secondary and (primary==secondary or primary.is_relative_to(secondary) or secondary.is_relative_to(primary)):raise RuntimeError('Los destinos deben ser independientes')
   for p in [primary,secondary]:
    if p:(p/'.sparrowfy-backup').write_text('Sparrow-Fy backup repository\n');(p/'.htaccess').write_text('Require all denied\n')
   write_json(SETTINGS,{'primary':str(primary),'secondary':str(secondary) if secondary else ''});print('Destinos guardados');return
  try:
   cfg=json.loads(SETTINGS.read_text()) if SETTINGS.exists() else {}
   BASE=configured_path(cfg.get('primary',str(ROOT/'backups')))
   SECONDARY=pathlib.Path(cfg['secondary']) if cfg.get('secondary') else None
   (BASE/'.sparrowfy-backup').write_text('Sparrow-Fy backup repository\n');(BASE/'.htaccess').write_text('Require all denied\n')
   for name in ['objects','snapshots','restore-tests']:(BASE/name).mkdir(exist_ok=True)
  except Exception as e:
   write_json(STATE,{'state':'failed','action':args.action,'finished':time.time(),'message':str(e)});raise
  write_json(STATE,{'state':'running','action':args.action,'started':time.time(),'pid':os.getpid()})
  try:
   if args.action.startswith('quarantine'):
    import sys,quarantine
    value=quarantine.run(sys.modules[__name__],args.action,args.item,args.confirmed);result='Cuarentena actualizada' if isinstance(value,list) else value
   elif args.action=='audit':
    report=audit();write_json(BASE/'latest-audit.json',report);result='Auditoría terminada: %d hallazgos'%len(report['issues'])
   elif args.action in ['verify','restore']:
    snaps=sorted((BASE/'snapshots').iterdir())
    if not snaps:raise RuntimeError('No hay respaldos disponibles')
    snap=BASE/'snapshots'/args.snapshot if args.snapshot else snaps[-1]
    if snap.parent.resolve()!=(BASE/'snapshots').resolve():raise RuntimeError('Snapshot inválido')
    required=sum(e['size'] for e in json.loads((snap/'manifest.json').read_text())['files'].values())+256*1024*1024
    if shutil.disk_usage(BASE).free<required:raise RuntimeError('Espacio insuficiente para restaurar')
    restored=verify_restore(snap,keep=args.action=='restore');result='Restauración verificada: '+str(restored['restored_files'])+' archivos'
    if 'destination' in restored:result+=' en '+restored['destination']
   else:
    if args.action=='auto' and (BASE/'last-success.json').exists() and time.time()-json.loads((BASE/'last-success.json').read_text())['time']<23*3600:return
    write_json(BASE/'latest-audit.json',audit())
    required=sum(f.stat().st_size for f,_ in source_files())*2+512*1024*1024
    if shutil.disk_usage(BASE).free<required:raise RuntimeError('Espacio insuficiente para respaldo y restauración aislada')
    name=backup();result='Respaldo y restauración verificados: '+name
    try:
     import replacement_retirement
     retirement=replacement_retirement.run(__import__('sys').modules[__name__])
    except Exception as retirement_error:
     retirement={'quarantined':0,'errors':[{'error':str(retirement_error)}]}
    if retirement['quarantined']:result+='; versiones anteriores apartadas: '+str(retirement['quarantined'])
    if retirement['errors']:result+='; algunas versiones anteriores requieren revisión'
    try:
     import abandoned_outputs
     abandoned=abandoned_outputs.run(__import__('sys').modules[__name__])
     n=abandoned['hls_quarantined']+abandoned['temporaries_quarantined']
     if n:result+='; salidas interrumpidas en cuarentena: '+str(n)
     if abandoned['errors']:result+='; revisar cuarentena de temporales'
    except Exception as output_error:
     result+='; revisar limpieza de salidas interrumpidas'
    write_json(BASE/'latest-audit.json',audit())
    if SECONDARY:
     try:mirror();result+='; segunda copia verificada'
     except Exception as e:
      write_json(BASE/'secondary-status.json',{'state':'failed','time':time.time(),'error':str(e)});raise RuntimeError('Respaldo principal correcto; falló la segunda copia: '+str(e))
   stamp=time.time()
   write_json(STATE,{'state':'completed','action':args.action,'finished':stamp,'message':result})
   error_file=BASE/'last-error.json'
   if error_file.exists():
    prior=json.loads(error_file.read_text())
    if prior.get('action')==args.action and not prior.get('resolved_at'):
     prior['resolved_at']=stamp;prior['resolution']=result;write_json(error_file,prior)
   with (BASE/'operations.jsonl').open('a') as log:log.write(json.dumps({'time':stamp,'action':args.action,'item':args.item,'state':'completed','message':result},ensure_ascii=False)+'\n')
   print(result)
  except Exception as e:
   write_json(BASE/'last-error.json',{'time':time.time(),'action':args.action,'item':args.item,'error':str(e)});write_json(STATE,{'state':'failed','action':args.action,'finished':time.time(),'message':str(e)});raise
if __name__=='__main__':main()
