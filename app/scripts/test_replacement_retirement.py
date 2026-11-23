"""Isolated retention tests; no production catalogue or audio is read or modified."""
import pathlib,tempfile,sqlite3,json,time,types,sys,importlib.util
ROOT=pathlib.Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'scripts'))
# The staging tree may use the installed quarantine implementation via an explicit test-only argument.
if len(sys.argv)>1:sys.path.append(str(pathlib.Path(sys.argv[1])/'scripts'))
import replacement_retirement as retirement

def scenario(mode):
 root=pathlib.Path(tempfile.mkdtemp(prefix='retirement-test-',dir=ROOT/'backups')).resolve();media=root/'hls_out';base=root/'snapshots';base.mkdir();(root/'data').mkdir();(root/'backups/replacements').mkdir(parents=True);media.mkdir()
 dbfile=root/'fixture.sqlite';db=sqlite3.connect(dbfile);db.executescript('CREATE TABLE tracks(id TEXT,hls_path TEXT,job_id TEXT);CREATE TABLE audio_edit_jobs(id TEXT,album_id TEXT,state TEXT);CREATE TABLE sf_processing_jobs(id TEXT,album_id TEXT,state TEXT);')
 aid='0123456789abcdef';tid='fedcba9876543210';paths={}
 for job in ['A','B','C']:
  folder=media/(aid+'_Fixture')/job;folder.mkdir(parents=True);(folder/'master.m3u8').write_text('#EXTM3U\nseg.ts\n');(folder/'seg.ts').write_bytes(b'fixture');paths[job]=str((folder/'master.m3u8').relative_to(media))
 if mode not in ['removed','shared']:db.execute('INSERT INTO tracks VALUES(?,?,?)',(tid,'hls_out/'+paths['C'],'C'))
 if mode=='shared':db.execute('INSERT INTO tracks VALUES(?,?,?)',('another-track','hls_out/'+paths['A'],'A'))
 db.execute('INSERT INTO sf_processing_jobs VALUES(?,?,?)',('first',aid,'failed' if mode=='unpublished' else 'done'));db.execute('INSERT INTO audio_edit_jobs VALUES(?,?,?)',('second',aid,'failed' if mode=='unpublished' else 'done'))
 if mode=='busy':db.execute('INSERT INTO audio_edit_jobs VALUES(?,?,?)',('active',aid,'running'))
 db.commit();db.close()
 def write_json(p,value):p.parent.mkdir(parents=True,exist_ok=True);tmp=p.with_suffix(p.suffix+'.tmp');tmp.write_text(json.dumps(value));tmp.replace(p)
 for name,old,new,operation in [('first','A','B','replacement'),('second','B','C','audio_edit')]:
  write_json(root/'backups/replacements'/(name+'.json'),{'version':2,'state':'prepared' if (mode=='unpublished' or name=='second') else 'published','operation':operation,'track':{'id':tid,'hls':'hls_out/'+paths[old]},'replacement_hls':'hls_out/'+paths[new],'retire_after':0})
 def dbopen():return sqlite3.connect('file:'+str(dbfile)+'?mode=ro',uri=True)
 def audit():
  with dbopen() as q:known={r[0] for r in q.execute('SELECT hls_path FROM tracks')}
  return {'issues':[{'kind':'possible_orphan_media','path':str(p.relative_to(media))} for p in media.rglob('master.m3u8') if 'hls_out/'+str(p.relative_to(media)) not in known],'retained_media':[]}
 m=types.SimpleNamespace(ROOT=root,BASE=base,MEDIA=media,dbopen=dbopen,audit=audit,write_json=write_json)
 write_json(base/'last-success.json',{'restore_verified':True,'snapshot':'verified'});files={'media/'+p:{} for p in paths.values()}
 if mode=='missing_backup':files.pop('media/'+paths['A'])
 write_json(base/'snapshots/verified/manifest.json',{'files':files})
 if mode=='missing_current':(media/paths['C']).unlink()
 result=retirement.run(m)
 if mode in ['chain','removed']:assert result['quarantined']==2 and not result['errors'],result;assert (media/paths['C']).is_file();again=retirement.run(m);assert again['quarantined']==0 and not again['errors']
 elif mode=='missing_backup':assert result['quarantined']==1 and result['deferred_reasons']['verified_backup_missing']==1,result
 elif mode=='shared':assert result['quarantined']==1 and len(result['errors'])==1 and (media/paths['A']).is_file(),result
 elif mode=='busy':assert result['quarantined']==0 and len(result['errors'])==2,result;assert (media/paths['A']).is_file()
 elif mode=='unpublished':assert result['quarantined']==0 and result['deferred_reasons']['publication_not_confirmed']==2,result
 elif mode=='missing_current':assert result['quarantined']==0 and result['deferred_reasons']['current_audio_unavailable']==2,result
 print('PASS retirement',mode)
if __name__=='__main__':
 (ROOT/'backups').mkdir(exist_ok=True)
 for mode in ['chain','removed','shared','missing_backup','busy','unpublished','missing_current']:scenario(mode)
