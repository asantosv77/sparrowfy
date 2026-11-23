import os
import sqlite3
import sys
import tempfile
import zipfile
from pathlib import Path

root = Path('/var/www/html/audio/data')
with tempfile.TemporaryDirectory() as tmp:
    target = Path(tmp) / 'library.sqlite'
    source = sqlite3.connect(Path(os.environ['HLS_MUSIC_LAB_SQLITE_DB']).as_uri() + '?mode=ro', uri=True)
    copy = sqlite3.connect(target)
    try:
        source.backup(copy)
        if copy.execute('pragma quick_check').fetchone() != ('ok',):
            raise RuntimeError('SQLite quick_check falló')
    finally:
        copy.close()
        source.close()
    with zipfile.ZipFile(sys.stdout.buffer, 'w', zipfile.ZIP_DEFLATED) as archive:
        archive.write(target, 'database/library.sqlite')
        for name in ('config.json', 'maintenance-settings.json', 'sqlite-catalog.enabled'):
            if (root / name).is_file():
                archive.write(root / name, 'data/' + name)
