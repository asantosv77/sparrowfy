"""Own fixtures only, including a PHP process killed with SIGKILL."""
import pathlib,tempfile,sqlite3,json,time,types,sys,subprocess,os,hashlib
ROOT=pathlib.Path(__file__).resolve().parent.parent;sys.path.insert(0,str(ROOT/'scripts'))
import abandoned_outputs as cleanup

def scenario(mode):
 root=pathlib.Path(tempfile.mkdtemp(prefix='abandoned-test-',dir=ROOT/'backups')).resolve();media=root/'hls_out';media.mkdir();(root/'data').mkdir();base=root/'backups';base.mkdir()
 aid='0123456789abcdef';task='a'*32;dbfile=root/'fixture.sqlite';db=sqlite3.connect(dbfile);db.executescript('CREATE TABLE tracks(id TEXT,hls_path TEXT,job_id TEXT);CREATE TABLE sf_processing_jobs(id TEXT,album_id TEXT,state TEXT);CREATE TABLE audio_edit_jobs(id TEXT,album_id TEXT,state TEXT);');db.execute('INSERT INTO audio_edit_jobs VALUES(?,?,?)',(task,aid,'running' if mode=='active' else 'failed'));db.commit()
 kind='edit_temp' if mode=='killed_temp' else 'hls_output'
 if mode in ['killed_hls','killed_temp']:
  folder=media/(aid+'_Fixture')/'partial';folder.mkdir(parents=True)
  code="define('LAB_ROOT',"+repr(str(root))+");define('LAB_DATA',LAB_ROOT.'/data');define('LAB_HLS',LAB_ROOT.'/hls_out');require "+repr(str(ROOT/'lib/media_log.php'))+";require "+repr(str(ROOT/'lib/output_lifecycle.php'))+";sf_media_context(['task_id'=>'"+task+"','album_id'=>'"+aid+"','operation'=>'audio_edit']);"
  if mode=='killed_temp':code+="$t=sf_edit_temp_begin('"+task+"','"+aid+"');file_put_contents($t['file'],'partial audio');echo $t['dir'].PHP_EOL;"
  else:code+="sf_output_checkpoint("+repr(str(folder))+",'building',['album_id'=>'"+aid+"']);file_put_contents("+repr(str(folder/'seg.ts'))+",'partial audio');echo "+repr(str(folder))+".PHP_EOL;"
  code+="flush();sleep(30);";proc=subprocess.Popen(['php','-r',code],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True);folder=pathlib.Path(proc.stdout.readline().strip());proc.kill();proc.wait(timeout=5);assert proc.returncode!=0 and (folder/'.sf-output.json').is_file()
 else:
  folder=media/(aid+'_Fixture')/'partial';folder.mkdir(parents=True);(folder/'seg.ts').write_text('partial audio');(folder/'.sf-output.json').write_text(json.dumps({'version':1,'kind':kind,'album_id':aid,'task_id':task,'state':'ready','pid':99999999,'process_start':'0','created_at':0,'updated_at':0}))
 old=time.time()-90000;marker=folder/'.sf-output.json';meta=json.loads(marker.read_text());meta.update(created_at=old,updated_at=old)
 if mode=='published':meta['state']='published'
 if mode=='owner_alive':meta['pid']=os.getpid();meta['process_start']=pathlib.Path('/proc/self/stat').read_text().rsplit(') ',1)[1].split()[19]
 marker.write_text(json.dumps(meta))
 for p in folder.rglob('*'):os.utime(p,(old,old))
 if mode=='recent':os.utime(folder/'seg.ts',None)
 if mode=='referenced':db.execute('INSERT INTO tracks VALUES(?,?,?)',('track','hls_out/'+str(folder.relative_to(media))+'/master.m3u8','partial'));db.commit()
 if mode=='unknown':db.execute('DELETE FROM audio_edit_jobs');db.commit()
 if mode=='no_marker':marker.unlink()
 if mode=='retained':
  receipts=root/'backups/replacements';receipts.mkdir();(receipts/'receipt.json').write_text(json.dumps({'track':{'hls':'hls_out/'+str(folder.relative_to(media))+'/master.m3u8'}}))

 def write_json(p,value):
  p.parent.mkdir(parents=True,exist_ok=True);tmp=p.with_suffix('.tmp');tmp.write_text(json.dumps(value));tmp.replace(p)
 def dbopen():return sqlite3.connect('file:'+str(dbfile)+'?mode=ro',uri=True)
 def audit():return {'issues':[],'retained_media':[]}
 m=types.SimpleNamespace(ROOT=root,BASE=base,MEDIA=media,dbopen=dbopen,audit=audit,write_json=write_json)
 files={}
 for f in media.rglob('*'):
  if f.is_file():files['media/'+str(f.relative_to(media))]={'size':f.stat().st_size,'sha256':cleanup.digest(f)}
 if mode=='missing_backup':files={}
 write_json(base/'last-success.json',{'restore_verified':mode!='unverified','snapshot':'verified'});write_json(base/'snapshots/verified/manifest.json',{'files':files})
 result=cleanup.run(m)
 if mode in ['killed_hls','killed_temp']:
  assert result['hls_quarantined']+result['temporaries_quarantined']==1 and not result['errors'],result
  assert not folder.exists()
  index=base/('temporary-quarantine/index.json' if mode=='killed_temp' else 'quarantine/index.json');records=json.loads(index.read_text());assert records[0]['state']=='quarantined';target=pathlib.Path(records[0]['folder']);assert target.is_dir() and any(f.is_file() and f.read_bytes()==b'partial audio' for f in target.rglob('*'))
  again=cleanup.run(m);assert again['hls_quarantined']+again['temporaries_quarantined']==0
 else:
  assert result['hls_quarantined']+result['temporaries_quarantined']==0 and folder.is_dir() and not result['errors'],result
  reasons={'active':'active_job','recent':'recent_activity','referenced':'referenced','unknown':'task_not_terminal','retained':'retained_version','missing_backup':'verified_backup_missing','published':'not_temporary','owner_alive':'owner_alive'}
  if mode in reasons:assert result['deferred'].get(reasons[mode])==1,result
 print('PASS abandoned outputs',mode)
if __name__=='__main__':
 (ROOT/'backups').mkdir(exist_ok=True)
 for mode in ['killed_hls','killed_temp','active','recent','referenced','unknown','no_marker','retained','missing_backup','published','owner_alive','unverified']:scenario(mode)
