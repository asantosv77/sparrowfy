"""Uso: python3 test_cron_mornye.py /ruta/a/una/copia/parcheada/sparrowfy_cron.py"""
import importlib.util
import subprocess
import sys
from pathlib import Path
from unittest.mock import patch

path = Path(sys.argv[1]).resolve()
spec = importlib.util.spec_from_file_location('cron_propuesto', path)
cron = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cron)
cases = [
    (('exited','none'), ('absent','none'), False),
    (('running','starting'), ('absent','none'), False),
    (('running','unhealthy'), ('exited','none'), False),
    (('running','healthy'), ('running','healthy'), False),
    (('running','healthy'), ('running','starting'), False),
    (('running','healthy'), ('paused','none'), False),
    (('running','healthy'), ('restarting','none'), False),
    (('running','none'), ('absent','none'), True),
    (('running','healthy'), ('created','none'), True),
    (('running','healthy'), ('exited','none'), True),
    (('running','healthy'), ('dead','none'), True),
    (('running','healthy'), ('running','unhealthy'), True),
]
for web, mornye, recover in cases:
    with patch.object(cron,'container_state',side_effect=[web,mornye]), patch.object(cron.subprocess,'run') as call:
        call.return_value.returncode = 0
        assert cron.ensure_mornye() == 0
        if recover:
            call.assert_called_once_with(['bash',str(cron.ROOT.parents[1]/'mornye-up.sh')],timeout=420)
        else:
            call.assert_not_called()
with patch.object(cron.subprocess, 'run', side_effect=subprocess.TimeoutExpired('docker',10)) as call:
    assert cron.ensure_mornye() == 1
    assert call.call_count == 1
with patch.object(cron,'container_state',side_effect=[('running','healthy'),('exited','none')]), patch.object(cron.subprocess,'run') as call:
    call.return_value.returncode = 10
    assert cron.ensure_mornye() == 10
print('OK: 14 escenarios simulados; ningún servicio real recibió comandos.')

