#!/usr/bin/env python3
"""Isolated SQLite/media fixture; never touches production audio or account data."""
import importlib.util,pathlib,tempfile,sqlite3,json,sys
ROOT=pathlib.Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'scripts'))
import maintenance as m,quarantine as q,replacement_retirement as retirement,time
with tempfile.TemporaryDirectory(prefix='integrity-test-',dir=ROOT/'data') as folder:
 root=pathlib.Path(folder);media=root/'media';base=root/'backups';base.mkdir();(root/'data').mkdir();(base/'replacements').mkdir();dbpath=root/'test.sqlite'
 db=sqlite3.connect(dbpath)
 db.executescript("CREATE TABLE tracks(id TEXT PRIMARY KEY,album_id TEXT,hls_path TEXT,job_id TEXT);CREATE TABLE audio_edit_jobs(album_id TEXT,state TEXT);CREATE TABLE sf_processing_jobs(album_id TEXT,state TEXT);")
 for job in ['current','previous','orphan']:
  p=media/'aaaaaaaaaaaaaaaa_Album'/job;p.mkdir(parents=True);(p/'master.m3u8').write_text('#EXTM3U\nseg.ts\n');(p/'seg.ts').write_bytes(b'audio')
 current='hls_out/aaaaaaaaaaaaaaaa_Album/current/master.m3u8';old='hls_out/aaaaaaaaaaaaaaaa_Album/previous/master.m3u8'
 db.execute('INSERT INTO tracks VALUES(?,?,?,?)',('track','aaaaaaaaaaaaaaaa',current,'current'));db.commit();db.close()
 (base/'replacements/job.json').write_text(json.dumps({'track':{'id':'track','hls':old},'replacement_hls':current}))
 m.ROOT=root;m.BASE=base;m.MEDIA=media;m.dbopen=lambda path=dbpath:sqlite3.connect(path)
 original_audit=m.audit;m.audit=lambda:original_audit(dbpath,media)
 report=m.audit();assert len(report['issues'])==1 and report['issues'][0]['path'].endswith('orphan/master.m3u8');assert len(report['retained_media'])==1
 try:q.run(m,'quarantine','aaaaaaaaaaaaaaaa_Album/current/master.m3u8');raise AssertionError('Active audio moved')
 except RuntimeError:pass
 q.run(m,'quarantine','aaaaaaaaaaaaaaaa_Album/previous/master.m3u8')
 assert not (media/'aaaaaaaaaaaaaaaa_Album/previous').exists()
 assert 'ya estaba' in q.run(m,'quarantine','aaaaaaaaaaaaaaaa_Album/previous/master.m3u8')
 records=json.loads((base/'quarantine/index.json').read_text());rid=records[-1]['id']
 q.run(m,'quarantine_restore',rid);assert 'ya se' in q.run(m,'quarantine_restore',rid)
 q.run(m,'quarantine','aaaaaaaaaaaaaaaa_Album/previous/master.m3u8');rid=json.loads((base/'quarantine/index.json').read_text())[-1]['id']
 try:q.run(m,'quarantine_delete',rid,False);raise AssertionError('Delete accepted without confirmation')
 except RuntimeError:pass
 q.run(m,'quarantine_delete',rid,True);assert 'ya estaba eliminada' in q.run(m,'quarantine_delete',rid,True)
 try:q.run(m,'quarantine_delete','0'*32,True);raise AssertionError('Unknown record accepted')
 except RuntimeError:pass
 assert (media/'aaaaaaaaaaaaaaaa_Album/current/seg.ts').read_bytes()==b'audio'
 # Replacement retirement: failed/incomplete work, grace and verified-backup gates.
 db=sqlite3.connect(dbpath);db.execute('ALTER TABLE sf_processing_jobs ADD COLUMN id TEXT');db.commit();db.close()
 previous=media/'aaaaaaaaaaaaaaaa_Album/previous';previous.mkdir();(previous/'master.m3u8').write_text('#EXTM3U\nseg.ts\n');(previous/'seg.ts').write_bytes(b'old audio')
 receipt=base/'replacements/job.json';data={'version':2,'state':'prepared','track':{'id':'track','hls':old},'replacement_hls':current,'prepared_at':time.time()-7200,'retire_after':time.time()-1}
 receipt.write_text(json.dumps(data))
 snap=base/'snapshots/test';snap.mkdir(parents=True);(snap/'manifest.json').write_text(json.dumps({'files':{}}));m.write_json(base/'last-success.json',{'restore_verified':True,'snapshot':'test'})
 assert retirement.run(m)['quarantined']==0 and previous.is_dir()
 data['state']='published';data['retire_after']=time.time()+3600;receipt.write_text(json.dumps(data))
 assert retirement.run(m)['quarantined']==0 and previous.is_dir()
 data['retire_after']=time.time()-1;receipt.write_text(json.dumps(data))
 assert retirement.run(m)['quarantined']==0 and previous.is_dir()
 (snap/'manifest.json').write_text(json.dumps({'files':{'media/'+old.removeprefix('hls_out/'): {},'media/'+current.removeprefix('hls_out/'): {}}}))
 db=sqlite3.connect(dbpath);db.execute("INSERT INTO sf_processing_jobs VALUES('aaaaaaaaaaaaaaaa','queued','active')");db.commit();db.close()
 assert retirement.run(m)['errors'] and previous.is_dir()
 db=sqlite3.connect(dbpath);db.execute("UPDATE sf_processing_jobs SET state='done'");db.commit();db.close()
 assert retirement.run(m)['quarantined']==1 and not previous.exists()
 assert retirement.run(m)['quarantined']==0
 assert (media/'aaaaaaaaaaaaaaaa_Album/current/seg.ts').read_bytes()==b'audio'
 print('PASS: retirement gates, failed publication preserved, active jobs defer, verified backup, idempotent quarantine')
 print('PASS: retained vs orphan, active audio protected, quarantine/restore, repeated operations, confirmation required')
