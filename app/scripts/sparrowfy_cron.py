#!/usr/bin/env python3
"""Host pi cron dispatcher. Run every minute; fixed commands only, no web input.
See deploy/SPARROWFY_CRON.md. --dry-run prints jobs without executing them.
"""
import argparse, datetime, fcntl, json, os, subprocess, sys
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parent.parent
STATE = ROOT / 'data' / 'cron-control'
# Las tareas se ejecutan localmente bajo www-data en el contenedor aislado.
JOBS = {
 'processing': ['sh', 'scripts/run_processing_cron.sh'],
 'usage': ['sh', '-c', 'umask 0007; exec nice -n 10 php scripts/usage_rollup.php'],
 'resources': ['/usr/bin/python3', str(ROOT / 'scripts/deploy_resources.py')],
 'music-charts': ['php', 'scripts/music_charts.php'],
 'external-ai': ['php', 'scripts/ai_external_maintenance.php'],
 'backup': ['sh', '-c', 'umask 0007; exec nice -n 15 python3 scripts/maintenance.py backup'],
}

def backup_due(now, state):
 if (now.hour, now.minute) < (4, 17): return False
 if state.get('success_date') == now.date().isoformat(): return False
 return now.timestamp() - state.get('attempt_at', 0) >= 3600

def read_state():
 try: return json.loads((STATE / 'backup.json').read_text())
 except (OSError, ValueError): return {}

def save_state(state):
 temp = STATE / 'backup.json.tmp'
 temp.write_text(json.dumps(state))
 os.chmod(temp, 0o660)
 temp.replace(STATE / 'backup.json')

def run_task(name):
 with (STATE / (name + '.lock')).open('a') as lock:
  try: fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
  except BlockingIOError: return
  now = datetime.datetime.now(ZoneInfo(os.environ.get('TZ', 'America/Mexico_City')))
  state = read_state() if name == 'backup' else {}
  if name == 'backup':
   if not backup_due(now, state): return
   state['attempt_at'] = now.timestamp()
   save_state(state)
  logfile = STATE / (name + '.log')
  if logfile.exists() and logfile.stat().st_size > 2097152:
   logfile.replace(STATE / (name + '.previous.log'))
  with logfile.open('a') as log:
   log.write(now.isoformat() + ' start ' + name + '\n'); log.flush()
   try: code = subprocess.run(JOBS[name], stdout=log, stderr=subprocess.STDOUT).returncode
   except OSError as exc: log.write(str(exc) + '\n'); code = 127
   log.write(datetime.datetime.now().isoformat() + ' exit=' + str(code) + '\n')
  if name == 'backup':
   state['exit_code'] = code
   if code == 0: state['success_date'] = now.date().isoformat()
   save_state(state)

def main():
 parser = argparse.ArgumentParser(description=__doc__)
 parser.add_argument('--task', choices=JOBS)
 parser.add_argument('--dry-run', action='store_true')
 args = parser.parse_args()
 os.umask(0o007)
 if args.dry_run:
  for name, command in JOBS.items(): print(name + ': ' + json.dumps(command))
  print('Backup: 04:17 America/Mexico_City; recover missed daily run, retry failures hourly.')
  return
 STATE.mkdir(parents=True, exist_ok=True)
 if args.task: return run_task(args.task)
 # Children hold individual locks. A long worker never blocks next minute's other jobs.
 with open(os.devnull, 'wb') as null:
  for name in JOBS:
   subprocess.Popen(['/usr/bin/python3', str(Path(__file__).resolve()), '--task', name],
                    stdin=subprocess.DEVNULL, stdout=null, stderr=null,
                    start_new_session=True, close_fds=True)

if __name__ == '__main__': main()
