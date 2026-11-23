"""Retire previous versions from replacements and trim/fade only after verified backup.
Moves to recoverable quarantine, never permanently deletes audio.
"""
import json,pathlib,time

def run(m):
 import quarantine
 result={'time':time.time(),'quarantined':0,'deferred':0,'absent':0,'deferred_reasons':{},'errors':[]}
 success_file=m.BASE/'last-success.json'
 if not success_file.exists():return result
 success=json.loads(success_file.read_text())
 if not success.get('restore_verified'):return result
 snapshot=str(success.get('snapshot',''))
 if pathlib.Path(snapshot).name!=snapshot:return result
 manifest=m.BASE/'snapshots'/snapshot/'manifest.json'
 if not manifest.is_file():return result
 files=json.loads(manifest.read_text()).get('files',{})
 def defer(receipt,data,reason):
  result['deferred']+=1;result['deferred_reasons'][reason]=result['deferred_reasons'].get(reason,0)+1
  if data.get('deferred_reason')!=reason:
   data['deferred_reason']=reason;m.write_json(receipt,data)
 for receipt in sorted((m.ROOT/'backups/replacements').glob('*.json')):
  try:
   data=json.loads(receipt.read_text())
   if data.get('version')!=2 or data.get('retirement')=='quarantined':continue
   old=data['track'];old_hls=old['hls'];relative=old_hls.removeprefix('hls_out/');parts=pathlib.PurePosixPath(relative)
   if not old_hls.startswith('hls_out/') or len(parts.parts)!=3 or parts.name!='master.m3u8' or '..' in parts.parts:continue
   source=m.MEDIA/relative
   if not source.is_file():
    result['absent']+=1
    if data.get('retirement')!='source_absent':data['retirement']='source_absent';m.write_json(receipt,data)
    continue
   with m.dbopen() as db:
    row=db.execute('SELECT hls_path FROM tracks WHERE id=?',(old['id'],)).fetchone()
    if row and row[0]==old_hls:defer(receipt,data,'source_still_current');continue
    current=row[0] if row else None
    # A prepared receipt is not permission to retire audio; require a completed publisher.
    if data.get('state')!='published':
     table='audio_edit_jobs' if data.get('operation')=='audio_edit' else 'sf_processing_jobs'
     job=db.execute('SELECT state FROM '+table+' WHERE id=?',(receipt.stem,)).fetchone()
     if not job or job[0]!='done':defer(receipt,data,'publication_not_confirmed');continue
   due=data.get('retire_after',data.get('prepared_at',receipt.stat().st_mtime)+max(3600,int(float(old.get('duration',0)))+600))
   if time.time()<due:defer(receipt,data,'retention_period');continue
   # A live track needs its current audio. A deleted track has no current version to verify;
   # its unreferenced former audio may enter quarantine only with its own verified backup.
   if current and (not current.startswith('hls_out/') or not (m.MEDIA/current.removeprefix('hls_out/')).is_file()):defer(receipt,data,'current_audio_unavailable');continue
   if 'media/'+relative not in files or (current and 'media/'+current.removeprefix('hls_out/') not in files):defer(receipt,data,'verified_backup_missing');continue
   # Rechecks every track reference, active jobs, containment and copy hashes under locks.
   quarantine.run(m,'quarantine',relative)
   index=json.loads((m.BASE/'quarantine/index.json').read_text())
   matches=[r for r in index if r['original']==str(parts.parent) and r['state']=='quarantined']
   if len(matches)!=1:raise RuntimeError('No se pudo confirmar la cuarentena de la versión anterior')
   data.update({'state':'published','retirement':'quarantined','quarantine_id':matches[0]['id'],'retired_at':time.time(),'verified_snapshot':snapshot,'retired_reason':'track_removed' if current is None else 'superseded'})
   data.pop('deferred_reason',None);m.write_json(receipt,data);result['quarantined']+=1
  except Exception as error:
   result['errors'].append({'receipt':receipt.name,'error':str(error)})
 m.write_json(m.BASE/'replacement-retirement.json',result)
 return result
