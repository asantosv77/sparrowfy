"""Quarantine app-marked terminal-task outputs after 24h; never permanently delete."""
import pathlib,json,time,re,os,hashlib,fcntl,shutil,uuid,datetime
AGE=86400

def digest(p):
 h=hashlib.sha256()
 with p.open('rb') as f:
  for block in iter(lambda:f.read(1048576),b''):h.update(block)
 return h.hexdigest()

def owner_alive(meta):
 try:return pathlib.Path('/proc/'+str(int(meta['pid']))+'/stat').read_text().rsplit(') ',1)[1].split()[19]==str(meta['process_start']) and bool(meta['process_start'])
 except (OSError,KeyError,ValueError,IndexError):return False

def eligible(m,folder,kind):
 folder=pathlib.Path(folder);marker=folder/'.sf-output.json'
 if folder.is_symlink() or folder.resolve()!=folder or not marker.is_file() or marker.is_symlink():return None,'invalid_marker'
 meta=json.loads(marker.read_text())
 if meta.get('version')!=1 or meta.get('kind')!=kind:return None,'unknown_owner'
 aid=meta.get('album_id','');task=meta.get('task_id','')
 if not isinstance(aid,str) or not re.fullmatch('[a-f0-9]{16}',aid) or not isinstance(task,str) or not re.fullmatch('[a-f0-9]{32}',task):return None,'unknown_task'
 root=m.MEDIA if kind=='hls_output' else m.ROOT/'data/audio-edit-temp'
 if not folder.is_relative_to(root.resolve()):return None,'outside_root'
 rel=folder.relative_to(root.resolve())
 if kind=='hls_output' and (len(rel.parts)!=2 or rel.parts[0][:16]!=aid):return None,'invalid_path'
 if kind=='edit_temp' and (len(rel.parts)!=1 or not re.fullmatch(task+'-[a-f0-9]{12}',rel.name)):return None,'invalid_path'
 if meta.get('state') not in ['building','ready']:return None,'not_temporary'
 if time.time()-max(float(meta.get('created_at',time.time())),float(meta.get('updated_at',time.time())))<AGE:return None,'too_recent'
 if owner_alive(meta):return None,'owner_alive'
 if any(p.is_file() and time.time()-p.stat().st_mtime<AGE for p in folder.rglob('*')):return None,'recent_activity'
 with m.dbopen() as db:
  found=[]
  for table in ['sf_processing_jobs','audio_edit_jobs']:
   row=db.execute('SELECT state FROM '+table+' WHERE id=?',(task,)).fetchone()
   if row:found.append(row[0])
   if db.execute('SELECT 1 FROM '+table+" WHERE album_id=? AND state IN ('queued','running') LIMIT 1",(aid,)).fetchone():return None,'active_job'
  if not found or any(s not in ['done','failed'] for s in found):return None,'task_not_terminal'
  if kind=='hls_output':
   prefix='hls_out/'+str(rel)+'/'
   if db.execute('SELECT 1 FROM tracks WHERE substr(hls_path,1,?)=? OR job_id=? LIMIT 1',(len(prefix),prefix,folder.name)).fetchone():return None,'referenced'
 if kind=='hls_output':
  path='hls_out/'+str(rel)+'/master.m3u8'
  for receipt in (m.ROOT/'backups/replacements').glob('*.json'):
   data=json.loads(receipt.read_text())
   if data.get('track',{}).get('hls')==path or (data.get('state')=='published' and data.get('replacement_hls')==path):return None,'retained_version'
 return meta,None

def verified_files(m,folder):
 success=json.loads((m.BASE/'last-success.json').read_text())
 if not success.get('restore_verified'):return False
 name=str(success.get('snapshot',''))
 if pathlib.Path(name).name!=name:return False
 files=json.loads((m.BASE/'snapshots'/name/'manifest.json').read_text()).get('files',{})
 for p in folder.rglob('*'):
  if p.is_symlink():return False
  if p.is_file():
   entry=files.get('media/'+str(p.relative_to(m.MEDIA)))
   if not entry or entry.get('size')!=p.stat().st_size or entry.get('sha256')!=digest(p):return False
 return True

def event(m,name,path,task):
 try:
  cfg=m.ROOT/'data/config.json'
  if cfg.is_file() and not json.loads(cfg.read_text()).get('media_operations_log_enabled',True):return
  d=m.ROOT/'data/media-log';d.mkdir(mode=0o2770,exist_ok=True);guard=open(d/'.lock','a');fcntl.flock(guard,fcntl.LOCK_EX|fcntl.LOCK_NB)
  try:
   target=d/'events.jsonl'
   if target.exists() and target.stat().st_size>=2097152:
    if target.with_name(target.name+'.5').exists():target.with_name(target.name+'.5').unlink()
    for i in range(4,0,-1):
     old=target.with_name(target.name+'.'+str(i))
     if old.exists():old.replace(target.with_name(target.name+'.'+str(i+1)))
    target.replace(target.with_name(target.name+'.1'))
   with target.open('a') as f:f.write(json.dumps({'time':datetime.datetime.now(datetime.timezone.utc).isoformat(),'event':name,'path':path,'task_id':task,'pid':os.getpid(),'request_id':uuid.uuid4().hex[:16]})+'\n')
   os.chmod(target,0o660);(d/'.htaccess').write_text('Require all denied\n')
  finally:fcntl.flock(guard,fcntl.LOCK_UN);guard.close()
 except Exception:pass

def temp_quarantine(m,folder):
 import quarantine
 lock=open(m.ROOT/'data/.track-reuse.lock','a');fcntl.flock(lock,fcntl.LOCK_EX)
 try:
  meta,reason=eligible(m,folder,'edit_temp')
  if reason:return reason
  local=open(folder/'.lock','a')
  try:
   try:fcntl.flock(local,fcntl.LOCK_EX|fcntl.LOCK_NB)
   except BlockingIOError:return 'owner_alive'
   files=quarantine.inventory(folder);base=m.BASE/'temporary-quarantine';base.mkdir(exist_ok=True);index=base/'index.json';records=json.loads(index.read_text()) if index.exists() else []
   rid=uuid.uuid4().hex;target=base/'items'/rid;target.parent.mkdir(exist_ok=True)
   shutil.copytree(folder,target,copy_function=shutil.copyfile)
   if quarantine.inventory(target)!=files:raise RuntimeError('Temporary quarantine copy mismatch; original retained')
   for p in [target,*target.rglob('*')]:os.chmod(p,0o2770 if p.is_dir() else 0o660)
   record={'id':rid,'original':str(folder.relative_to(m.ROOT)),'folder':str(target),'task_id':meta['task_id'],'state':'copied','files':files,'created_at':time.time()};records.append(record);m.write_json(index,records)
   shutil.rmtree(folder);record['state']='quarantined';m.write_json(index,records)
   event(m,'temporary.quarantined',record['original'],meta['task_id']);return None
  finally:fcntl.flock(local,fcntl.LOCK_UN);local.close()
 finally:fcntl.flock(lock,fcntl.LOCK_UN);lock.close()

def run(m):
 import quarantine
 result={'time':time.time(),'hls_quarantined':0,'temporaries_quarantined':0,'deferred':{},'errors':[]}
 success=m.BASE/'last-success.json'
 if not success.is_file() or not json.loads(success.read_text()).get('restore_verified'):return result
 for kind,markers in [('hls_output',list(m.MEDIA.glob('*/*/.sf-output.json'))),('edit_temp',list((m.ROOT/'data/audio-edit-temp').glob('*/.sf-output.json')))]:
  for marker in markers:
   folder=marker.parent
   try:
    meta,reason=eligible(m,folder,kind)
    if not reason and kind=='hls_output' and not verified_files(m,folder):reason='verified_backup_missing'
    if reason:result['deferred'][reason]=result['deferred'].get(reason,0)+1;continue
    if kind=='hls_output':
     quarantine.run(m,'quarantine_output',str(folder.relative_to(m.MEDIA)));result['hls_quarantined']+=1;event(m,'output.abandoned_quarantined','hls_out/'+str(folder.relative_to(m.MEDIA)),meta['task_id'])
    else:
     reason=temp_quarantine(m,folder)
     if reason:result['deferred'][reason]=result['deferred'].get(reason,0)+1;continue
     result['temporaries_quarantined']+=1
   except Exception as e:result['errors'].append({'kind':kind,'item':folder.name,'error_class':type(e).__name__,'error_fingerprint':hashlib.sha256(str(e).encode()).hexdigest()})
 m.write_json(m.BASE/'abandoned-outputs.json',result);return result
