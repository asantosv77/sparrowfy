"""Métricas del contenedor web de pruebas desde cgroups v2, sin socket Docker."""
import json
import os
import shutil
import time
from pathlib import Path

out = Path(__file__).resolve().parents[1] / 'data/container-memory'
out.mkdir(parents=True, exist_ok=True)
cgroup = Path('/sys/fs/cgroup')
for _ in range(11):
    data = {'updated_at': int(time.time()), 'containers': {}}
    try:
        used = int((cgroup / 'memory.current').read_text())
        maximum = (cgroup / 'memory.max').read_text().strip()
        if maximum == 'max':
            maximum = next(int(line.split()[1]) * 1024 for line in Path('/proc/meminfo').read_text().splitlines() if line.startswith('MemTotal:'))
        data['containers']['www'] = {'usage': f'{used // 1048576} MiB', 'percent': round(100 * used / int(maximum), 1)}
    except (OSError, ValueError, StopIteration):
        data['error'] = 'Lectura de memoria no disponible'
    disk = shutil.disk_usage('/sparrowfy/hls_out')
    data['storage'] = {'percent': round(100 * disk.used / disk.total, 2), 'used': disk.used, 'total': disk.total, 'free': disk.free}
    temp = out / 'latest.tmp'
    temp.write_text(json.dumps(data))
    os.chmod(temp, 0o660)
    temp.replace(out / 'latest.json')
    time.sleep(5)
