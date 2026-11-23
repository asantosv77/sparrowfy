"""Prueba del primer administrador en un contenedor efímero sin volúmenes."""
import http.cookiejar
import json
import os
import re
import secrets
import sqlite3
import subprocess
import time
import urllib.parse
import urllib.request
from pathlib import Path

db = Path(os.environ['HLS_MUSIC_LAB_SQLITE_DB'])
if db.exists():
    raise SystemExit('La prueba requiere un contenedor vacío sin volúmenes existentes.')
server = subprocess.Popen(['/usr/local/bin/sparrowfy-start'], stdout=subprocess.DEVNULL)
base = 'http://127.0.0.1:8080'
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def get(path):
    return client.open(base + path, timeout=8)
def setup(password):
    env = dict(os.environ, HLS_ADMIN_USER='admin_prueba', HLS_ADMIN_PASSWORD=password)
    subprocess.run(['php', 'scripts/auth_setup.php'], env=env, check=True)
try:
    for attempt in range(40):
        try:
            with get('/api/v1/index.php?r=health') as reply:
                assert json.load(reply)['status'] == 'ok'
            break
        except OSError:
            if server.poll() is not None or attempt == 39:
                raise
            time.sleep(1)
    album = Path('data/albums/abcdabcdabcdabcd')
    album.mkdir()
    cover = Path('assets/pwa-180.png').read_bytes()
    (album / 'cover.png').write_bytes(cover)
    with get('/' + str(album / 'cover.png')) as reply:
        assert reply.status == 200 and reply.read() == cover
    (album / 'metadata.json').write_text('{}')
    try:
        get('/' + str(album / 'metadata.json'))
    except urllib.error.HTTPError as exc:
        assert exc.code == 403
    else:
        raise AssertionError('Metadatos privados accesibles')
    with sqlite3.connect(db) as conn:
        assert conn.execute('SELECT count(*) FROM users').fetchone() == (0,)
        password = secrets.token_urlsafe(27)
        setup(password)
        original = conn.execute('SELECT id,password_hash,role,is_active,can_manage_albums FROM users').fetchone()
        assert original[0] and original[1] != password and original[2:] == ('admin',1,1)
        try:
            conn.execute('UPDATE users SET is_active=0 WHERE id=?',(original[0],))
        except sqlite3.IntegrityError:
            conn.rollback()
        else:
            raise AssertionError('La protección SQLite del principal no actuó')
        setup(secrets.token_urlsafe(27))
        assert conn.execute('SELECT id,password_hash,role,is_active,can_manage_albums FROM users').fetchall() == [original]
    with get('/login.php') as reply:
        html = reply.read().decode()
    csrf = re.search(r'name="csrf_token"\s+value="([^"]+)"', html).group(1)
    data = urllib.parse.urlencode({'username':'admin_prueba','password':password,'csrf_token':csrf,'next':'/admin_users.php'}).encode()
    with client.open(base+'/login.php', data=data, timeout=15) as reply:
        page = reply.read().decode()
        assert reply.status == 200 and urllib.parse.urlsplit(reply.geturl()).path == '/admin_users.php', (reply.status,reply.geturl())
        assert 'admin_prueba' in page
    with get('/index.php?view=favorites') as reply:
        assert reply.status == 200 and 'login.php' not in reply.geturl()
    print('OK: base vacía, administrador principal protegido, segundo intento idempotente, login web, administración y favoritos.')
finally:
    server.terminate()
    server.wait(timeout=15)

