import os
import sqlite3
import tempfile
from pathlib import Path

db = Path(os.environ['HLS_MUSIC_LAB_SQLITE_DB'])
if not db.exists():
    db.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(dir=db.parent) as tmp:
        pending = Path(tmp) / 'library.sqlite'
        conn = sqlite3.connect(pending)
        try:
            conn.executescript(Path('/opt/sparrowfy/schema.sql').read_text())
            assert conn.execute('pragma quick_check').fetchone() == ('ok',)
        finally:
            conn.close()
        try:
            os.link(pending, db)
        except FileExistsError:
            pass
