#!/usr/bin/env python3
"""Regenera escaleras HLS alineadas a partir del plan de desalineacion.

Estrategia: un unico pase de ffmpeg por pista (asplit + var_stream_map) de
modo que todas las calidades comparten el mismo reloj de decodificacion y
codificacion. Es la unica forma que ha dado 0,0 ms de desfase en todas las
pistas del piloto, incluida una cuyo desfase original variaba con el tiempo.

Flujo por pista:
  1. Elegir la mejor entrada: source.m4a si se conserva; si no, el tramo
     superior actual (prog.m3u8 de la calidad mas alta por BANDWIDTH).
  2. Construir la escalera en /sparrowfy/realign-work/<job>/ (mismo sistema
     de archivos, para que el intercambio sea un rename atomico).
  3. Validar: decodificacion completa del master, duraciones coherentes y
     desfase < 1,5 ms entre calidades en dos instantes distintos.
  4. Publicar solo con --publish: la carpeta anterior se mueve a
     /sparrowfy/realign-retained/<job>.<utc>/ (no se borra nada) y la nueva
     ocupa su lugar. Si el segundo rename falla, se restaura la anterior.
  5. Recibo JSON en /sparrowfy/realign-receipts/<job>.json y linea en
     /sparrowfy/realign-state.jsonl (permite reanudar).

Por defecto NO publica: hace el trabajo y valida, dejando el resultado en
/sparrowfy/realign-work para inspeccion.

Uso (dentro del contenedor, como www-data):
    python3 scripts/realign_hls.py --limit 3
    python3 scripts/realign_hls.py --track 84efde38a8eb4347 --publish
    python3 scripts/realign_hls.py --album 6cbd0044b08241fe --publish
    python3 scripts/realign_hls.py --all --publish --resume

Requiere ffmpeg, ffprobe y numpy.

Cada ejecucion toma un bloqueo exclusivo en /sparrowfy/realign.pid (flock) y
falla con codigo 2 si ya hay otra en marcha; el archivo guarda el PID y se
retira al terminar. Con --resume se puede relanzar sin repetir lo publicado.
"""

import argparse
import atexit
import datetime as dt
import json
import os
import re
import shutil
import subprocess
import sys
import time
from pathlib import Path

try:
    import fcntl
except ImportError:  # fuera de Linux: se sigue sin bloqueo
    fcntl = None

try:
    import numpy as np
except ImportError:
    sys.stderr.write("Falta numpy (python3-numpy).\n")
    sys.exit(2)

SAMPLE_RATE = 8000
MAX_OFFSET_MS = 80.0
MAX_DRIFT_MS = 1.5
DURATION_TOLERANCE = 0.30
REFERENCE_OFFSET = 1000

WORK = Path("/sparrowfy/realign-work")
RETAINED = Path("/sparrowfy/realign-retained")
RECEIPTS = Path("/sparrowfy/realign-receipts")
STATE = Path("/sparrowfy/realign-state.jsonl")


def log(msg=""):
    print(msg, flush=True)


def acquire_lock(path):
    """Bloqueo exclusivo con el PID dentro; devuelve (handle, pid_ajeno).

    Si ya hay otra instancia, el kernel lo detecta con flock: no depende de
    que el archivo se haya borrado bien (un proceso muerto libera el lock solo).
    """
    if fcntl is None:
        log("aviso: sin fcntl; no se evitan ejecuciones simultaneas")
        return None, None
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    handle = path.open("a+", encoding="utf-8")
    try:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        handle.seek(0)
        other = handle.read().strip() or "desconocido"
        handle.close()
        return None, other
    handle.seek(0)
    handle.truncate()
    handle.write("%d\n" % os.getpid())
    handle.flush()
    os.fsync(handle.fileno())
    return handle, None


def release_lock(handle, path):
    if handle is None:
        return
    try:
        handle.close()
        Path(path).unlink()
    except OSError:
        pass


def run(cmd, timeout=7200):
    return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)


def check(cmd, what, timeout=7200):
    proc = run(cmd, timeout=timeout)
    if proc.returncode != 0:
        raise RuntimeError("%s: %s" % (what, (proc.stderr or proc.stdout)[-600:]))
    return proc


def variants(master_path):
    """Calidades ordenadas por BANDWIDTH (no por orden del archivo)."""
    text = Path(master_path).read_text(encoding="utf-8", errors="replace")
    entries, bandwidth = [], None
    for line in text.splitlines():
        line = line.strip()
        if line.startswith("#EXT-X-STREAM-INF:"):
            match = re.search(r"BANDWIDTH=(\d+)", line)
            bandwidth = int(match.group(1)) if match else None
        elif line and not line.startswith("#"):
            match = re.match(r"^([^/\n]+)/prog\.m3u8$", line)
            if match:
                entries.append((bandwidth if bandwidth is not None else -1, match.group(1)))
            bandwidth = None
    if not entries:
        entries = [(-1, name) for name in
                   re.findall(r"^([^/\n]+)/prog\.m3u8\s*$", text, re.M)]
    return [name for _, name in sorted(entries, key=lambda item: item[0])]


def probe_duration(playlist):
    out = check(["ffprobe", "-v", "error", "-show_entries", "format=duration",
                 "-of", "default=nw=1:nk=1", str(playlist)], "ffprobe").stdout.strip()
    try:
        return float(out)
    except ValueError:
        return None


def probe_rate(playlist):
    out = check(["ffprobe", "-v", "error", "-select_streams", "a:0",
                 "-show_entries", "stream=sample_rate", "-of", "default=nw=1:nk=1",
                 str(playlist)], "ffprobe").stdout.strip()
    return int(out) if out.isdigit() else 44100


def decode(playlist, start, duration=1.4):
    proc = subprocess.run(
        ["ffmpeg", "-nostdin", "-v", "error", "-threads", "1",
         "-ss", "%.3f" % start, "-t", "%.3f" % duration,
         "-i", str(playlist), "-map", "0:a:0", "-ac", "1",
         "-ar", str(SAMPLE_RATE), "-f", "f32le", "-"],
        capture_output=True, timeout=180)
    samples = len(proc.stdout) // 4
    if samples < SAMPLE_RATE // 2:
        return None
    return np.frombuffer(proc.stdout[:samples * 4], dtype=np.float32).astype(np.float64)


def lag_ms(a, b):
    max_lag = int(MAX_OFFSET_MS * SAMPLE_RATE / 1000)
    window = int(1000 * SAMPLE_RATE / 1000)
    if a is None or b is None:
        return None
    if len(a) < REFERENCE_OFFSET + window or len(b) < REFERENCE_OFFSET + window + max_lag:
        return None
    ref = a[REFERENCE_OFFSET:REFERENCE_OFFSET + window]
    energy = float(np.dot(ref, ref))
    if energy <= 1e-6:
        return None
    segment = b[REFERENCE_OFFSET - max_lag:REFERENCE_OFFSET + window + max_lag]
    corr = np.correlate(segment, ref, mode="valid")
    cumulative = np.concatenate(([0.0], np.cumsum(segment * segment)))
    windows = cumulative[window:] - cumulative[:-window]
    normalized = corr / (np.sqrt(np.maximum(windows, 0.0) * energy) + 1e-9)
    index = int(np.argmax(normalized))
    return (index - max_lag) / SAMPLE_RATE * 1000.0, float(normalized[index])


def measure_offsets(base, qualities, duration):
    """Desfase de cada calidad baja respecto a la mas alta, en dos instantes."""
    top = base / qualities[-1] / "prog.m3u8"
    points = [min(30.0, max(1.0, duration * 0.2)), max(0.5, duration * 0.5)]
    result = {}
    for quality in qualities[:-1]:
        other = base / quality / "prog.m3u8"
        values = []
        for start in points:
            found = lag_ms(decode(top, start), decode(other, start))
            if found and found[1] >= 0.5:
                values.append(round(found[0], 2))
        result[quality] = values
    return result


def build(base, qualities, source, entries, rate, segment=4):
    """Un solo ffmpeg: todas las calidades salen del mismo reloj."""
    for quality in qualities:
        (base / quality).mkdir(parents=True, exist_ok=True)
    segment_pattern = str(base / "%v" / "seg_%03d.ts")
    playlist_pattern = str(base / "%v" / "prog.m3u8")
    common = ["ffmpeg", "-nostdin", "-hide_banner", "-loglevel", "error", "-y",
              "-i", str(source)]
    if len(qualities) == 1:
        cmd = common + ["-map", "0:a:0", "-c:a", "aac", "-b:a:0", entries[0]["bitrate"],
                        "-ac", "2", "-ar", str(rate),
                        "-max_muxing_queue_size", "1024", "-f", "hls",
                        "-hls_time", str(segment), "-hls_playlist_type", "vod",
                        "-hls_flags", "independent_segments",
                        "-hls_segment_filename", segment_pattern,
                        playlist_pattern.replace("%v", qualities[0])]
        check(cmd, "ffmpeg")
        return
    split = "[0:a:0]asplit=%d%s" % (
        len(qualities), "".join("[a%d]" % i for i in range(len(qualities))))
    cmd = common + ["-filter_complex", split]
    for index, entry in enumerate(entries):
        cmd += ["-map", "[a%d]" % index, "-c:a", "aac",
                "-b:a:%d" % index, entry["bitrate"], "-ac", "2", "-ar", str(rate)]
    cmd += ["-max_muxing_queue_size", "1024", "-f", "hls",
            "-hls_time", str(segment), "-hls_playlist_type", "vod",
            "-hls_flags", "independent_segments",
            "-hls_segment_filename", segment_pattern,
            "-var_stream_map", " ".join("a:%d,name:%s" % (i, q)
                                        for i, q in enumerate(qualities)),
            playlist_pattern]
    check(cmd, "ffmpeg")


def validate(base, qualities, info_duration):
    """Comprueba que la escalera nueva se decodifica y queda alineada."""
    master = base / "master.m3u8"
    if not master.is_file():
        raise RuntimeError("falta master.m3u8")
    listed = variants(master)
    if listed != qualities:
        raise RuntimeError("master lista %s, esperaba %s" % (listed, qualities))
    durations = {}
    for quality in qualities:
        playlist = base / quality / "prog.m3u8"
        if not playlist.is_file():
            raise RuntimeError("falta %s/prog.m3u8" % quality)
        durations[quality] = probe_duration(playlist)
    top_duration = durations[qualities[-1]]
    if top_duration is None:
        raise RuntimeError("duracion ilegible")
    if info_duration and abs(top_duration - float(info_duration)) > DURATION_TOLERANCE:
        raise RuntimeError("duracion %s difiere de info.json %s" % (top_duration, info_duration))
    spread = max(abs(durations[q] - top_duration) for q in qualities)
    if spread > 0.06:
        raise RuntimeError("duraciones dispares entre calidades: %.3f s" % spread)
    check(["ffmpeg", "-nostdin", "-v", "error", "-i", str(master), "-f", "null", "-"],
          "decodificacion del master")
    offsets = measure_offsets(base, qualities, top_duration)
    for quality, values in offsets.items():
        if not values:
            raise RuntimeError("no se pudo medir el desfase de %s" % quality)
        if max(abs(v) for v in values) > MAX_DRIFT_MS:
            raise RuntimeError("desfase de %s fuera de tolerancia: %s" % (quality, values))
        if len(values) == 2 and abs(values[0] - values[1]) > MAX_DRIFT_MS:
            raise RuntimeError("desfase variable en %s: %s" % (quality, values))
    return {"durations": durations, "offsets": offsets}


def dir_size(path):
    return sum(f.stat().st_size for f in Path(path).glob("*/*.ts"))


def copy_extras(base, work, qualities):
    """Conserva info.json, la fuente y otros archivos del trabajo original."""
    for item in Path(base).iterdir():
        if item.name in qualities or item.name == "master.m3u8":
            continue
        if item.is_dir() or item.name.startswith(".") or item.name.endswith(".tmp"):
            continue
        shutil.copy2(item, Path(work) / item.name)


def normalize_perms(root):
    """Deja la escalera con el modo canonico de hls_out: 2775 / 664.

    La carpeta se construye en realign-work y luego se renombra, asi que no
    hereda los permisos del album de destino; se fijan aqui, antes de publicar,
    para que cada pista nueva quede igual que el resto del arbol.
    """
    root = Path(root)
    os.chmod(root, 0o2775)
    for current, dirs, files in os.walk(root):
        for name in dirs:
            os.chmod(os.path.join(current, name), 0o2775)
        for name in files:
            os.chmod(os.path.join(current, name), 0o664)


def current_dirs(database):
    """Carpeta HLS vigente por pista segun la base (fuente de verdad)."""
    import sqlite3
    dirs = {}
    try:
        source = sqlite3.connect("file:%s?mode=ro" % database, uri=True, timeout=15)
        try:
            for track_id, hls_path in source.execute(
                    "SELECT id,hls_path FROM tracks WHERE hls_path IS NOT NULL"):
                relative = str(hls_path).replace("hls_out/", "", 1)
                dirs[track_id] = Path("/sparrowfy/hls_out") / Path(relative).parent
        finally:
            source.close()
    except sqlite3.Error as error:
        log("aviso: no se pudo leer la base (%s); se usa el plan" % error)
    return dirs


def load_state():
    done = set()
    if STATE.is_file():
        for line in STATE.read_text(encoding="utf-8", errors="replace").splitlines():
            try:
                item = json.loads(line)
            except ValueError:
                continue
            if item.get("status") == "published":
                done.add(item.get("id"))
    return done


def append_state(item):
    STATE.parent.mkdir(parents=True, exist_ok=True)
    with STATE.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(item, ensure_ascii=False) + "\n")


def realign(entry, publish, force, database_dirs=None):
    track_id = entry["id"]
    base = (database_dirs or {}).get(track_id) or Path(entry["dir"])
    if not (base / "info.json").is_file():
        raise RuntimeError("la carpeta del plan no existe: %s" % base)
    info_path = base / "info.json"
    info = json.loads(info_path.read_text(encoding="utf-8"))
    qualities = variants(base / "master.m3u8")
    if len(qualities) < 2:
        return {"id": track_id, "status": "skipped", "reason": "una sola calidad"}
    rate = probe_rate(base / qualities[-1] / "prog.m3u8")

    source_name = info.get("source_file")
    source = base / str(source_name) if source_name else None
    using_source = bool(source and source.is_file())
    input_path = source if using_source else base / qualities[-1] / "prog.m3u8"

    entries = []
    for quality in qualities:
        kbps = int(re.sub(r"\D", "", quality) or 0)
        entries.append({"name": quality, "bitrate": quality,
                        "bandwidth": kbps * 1000})

    job = base.name
    work = WORK / job
    if work.exists():
        shutil.rmtree(work)
    work.mkdir(parents=True)

    log("  construyendo desde %s (%s)" % (
        "fuente original" if using_source else "el tramo superior",
        input_path.name))
    started = time.time()
    build(work, qualities, input_path, entries, rate)
    elapsed = time.time() - started

    master = ["#EXTM3U", "#EXT-X-VERSION:3", ""]
    for item in entries:
        master += ["#EXT-X-STREAM-INF:BANDWIDTH=%d,AVERAGE-BANDWIDTH=%d,"
                   "CODECS=\"mp4a.40.2\",NAME=\"%s\"" % (
                       int(item["bandwidth"] * 1.15), item["bandwidth"], item["name"]),
                   "%s/prog.m3u8" % item["name"], ""]
    (work / "master.m3u8").write_text("\n".join(master), encoding="utf-8")

    try:
        before = measure_offsets(base, qualities, info.get("duration_sec") or 0)
    except Exception:
        before = {}
    checks = validate(work, qualities, info.get("duration_sec"))
    copy_extras(base, work, qualities)
    normalize_perms(work)

    old_size = dir_size(base)
    new_size = dir_size(work)
    receipt = {
        "id": track_id,
        "name": entry.get("name"),
        "job": job,
        "dir": str(base),
        "strategy": "single-pass",
        "input": "source" if using_source else "top",
        "qualities": qualities,
        "rate": rate,
        "elapsed_sec": round(elapsed, 1),
        "offsets_before_ms": before,
        "offsets_after_ms": checks["offsets"],
        "old_bytes": old_size,
        "new_bytes": new_size,
        "validated_at": dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds"),
        "published": False,
    }

    if not publish:
        receipt["status"] = "validated"
        work_kept = str(work)
        receipt["work_dir"] = work_kept
        return receipt

    stamp = dt.datetime.now(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    retained = RETAINED / ("%s.%s" % (job, stamp))
    RETAINED.mkdir(parents=True, exist_ok=True)
    if retained.exists():
        raise RuntimeError("ya existe %s" % retained)
    os.rename(base, retained)
    try:
        os.rename(work, base)
    except Exception:
        os.rename(retained, base)
        raise
    receipt["retained_dir"] = str(retained)
    receipt["published"] = True
    receipt["status"] = "published"

    # Deja constancia dentro del propio info.json.
    try:
        info = json.loads((base / "info.json").read_text(encoding="utf-8"))
        info["realign"] = {
            "at": receipt["validated_at"],
            "strategy": "single-pass",
            "input": receipt["input"],
            "offsets_before_ms": before,
            "offsets_after_ms": checks["offsets"],
            "retained_dir": str(retained),
        }
        (base / "info.json.tmp").write_text(
            json.dumps(info, ensure_ascii=False, indent=1), encoding="utf-8")
        os.chmod(base / "info.json.tmp", 0o664)
        os.replace(base / "info.json.tmp", base / "info.json")
    except Exception as error:
        log("  aviso: no se pudo anotar info.json: %s" % error)

    RECEIPTS.mkdir(parents=True, exist_ok=True)
    (RECEIPTS / ("%s.json" % job)).write_text(
        json.dumps(receipt, ensure_ascii=False, indent=1), encoding="utf-8")
    append_state({"id": track_id, "job": job, "status": "published",
                  "at": receipt["validated_at"], "retained": str(retained)})
    return receipt


def main():
    parser = argparse.ArgumentParser(
        description="Regenera escaleras HLS alineadas (un solo pase por pista).")
    parser.add_argument("--plan", default="/sparrowfy/hls_realign_plan.json")
    parser.add_argument("--db", default="/sparrowfy/database/library.sqlite",
                        help="Base del catalogo; su ruta HLS manda sobre el plan")
    parser.add_argument("--track", action="append", default=[])
    parser.add_argument("--album", default=None)
    parser.add_argument("--limit", type=int, default=0)
    parser.add_argument("--all", action="store_true", help="Recorre todo el plan")
    parser.add_argument("--publish", action="store_true",
                        help="Publica el resultado (sin esto solo valida)")
    parser.add_argument("--resume", action="store_true",
                        help="Salta las pistas ya publicadas segun el estado")
    parser.add_argument("--force", action="store_true",
                        help="Rehace pistas ya publicadas")
    parser.add_argument("--json-out", default="/sparrowfy/realign-run.json")
    parser.add_argument("--pidfile", default="/sparrowfy/realign.pid",
                        help="PID y bloqueo de instancia unica (flock)")
    parser.add_argument("--no-lock", action="store_true",
                        help="No bloquear aunque haya otra ejecucion")
    args = parser.parse_args()

    if not args.no_lock:
        try:
            lock, other = acquire_lock(args.pidfile)
        except OSError as error:
            log("No se pudo usar el pidfile %s: %s" % (args.pidfile, error))
            return 2
        if other:
            log("Ya hay una ejecucion en curso (pid %s)." % other)
            log("Espera a que termine, usa --resume al relanzar o --pidfile distinto.")
            return 2
        atexit.register(release_lock, lock, args.pidfile)
        if lock is not None:
            log("pid %d | bloqueo %s" % (os.getpid(), args.pidfile))

    plan = json.loads(Path(args.plan).read_text(encoding="utf-8"))
    tracks = plan.get("tracks", plan)
    if args.track:
        wanted = set(args.track)
        tracks = [t for t in tracks if t["id"] in wanted]
    if args.album:
        tracks = [t for t in tracks if t.get("album_id") == args.album]
    if not args.all and not args.track and not args.album:
        tracks = tracks[:args.limit or 1]
    elif args.limit:
        tracks = tracks[:args.limit]

    done = load_state() if (args.resume and not args.force) else set()
    if done:
        tracks = [t for t in tracks if t["id"] not in done]
        log("Reanudando: %d pistas ya publicadas se omiten" % len(done))

    database_dirs = current_dirs(args.db)
    missing = [t["id"] for t in tracks
               if t["id"] not in database_dirs and not Path(t["dir"]).is_dir()]
    if missing:
        log("aviso: %d pistas sin carpeta en la base ni en el plan" % len(missing))

    if not tracks:
        log("Nada que hacer.")
        return 0

    log("%d pistas | publicar=%s | trabajo en %s" % (
        len(tracks), "SI" if args.publish else "NO (solo validar)", WORK))
    results = []
    failures = 0
    for position, entry in enumerate(tracks, 1):
        log("\n[%d/%d] %s (%s)" % (position, len(tracks),
                                   entry.get("name", "?"), entry["id"]))
        try:
            receipt = realign(entry, args.publish, args.force, database_dirs)
            results.append(receipt)
            if receipt.get("status") == "skipped":
                log("  omitida: %s" % receipt.get("reason"))
            else:
                log("  desfase antes: %s" % receipt.get("offsets_before_ms"))
                log("  desfase despues: %s" % receipt.get("offsets_after_ms"))
                log("  tamano: %.1f MiB -> %.1f MiB" % (
                    receipt["old_bytes"] / 1048576.0, receipt["new_bytes"] / 1048576.0))
                log("  %s" % ("PUBLICADA" if receipt.get("published")
                              else "validada en %s" % receipt.get("work_dir")))
        except Exception as error:
            failures += 1
            log("  ERROR: %s" % error)
            if os.environ.get("REALIGN_DEBUG"):
                import traceback
                traceback.print_exc()
            results.append({"id": entry["id"], "name": entry.get("name"),
                            "status": "failed", "error": str(error)})
        try:
            Path(args.json_out).write_text(
                json.dumps({"generated_at": dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds"),
                            "publish": args.publish, "results": results},
                           ensure_ascii=False, indent=1), encoding="utf-8")
        except OSError:
            pass

    ok = sum(1 for r in results if r.get("status") in ("published", "validated"))
    log("\nResumen: %d correctas, %d errores, %d omitidas" % (
        ok, failures, sum(1 for r in results if r.get("status") == "skipped")))
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
