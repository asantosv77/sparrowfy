#!/usr/bin/env python3
"""Analiza la alineacion temporal entre las calidades HLS de cada pista.

Solo lectura: no reescribe hls_out, ni los playlists, ni la base de datos.
Detecta pistas cuya escalera mezcla codificadores (WebCodecs del navegador
frente a ffmpeg del servidor) y por tanto entra con unos milisegundos de
retraso al cambiar de calidad durante la reproduccion.

Uso (dentro del contenedor, como www-data):
    python3 scripts/align_hls.py
    python3 scripts/align_hls.py --sample 50 --seed 7
    python3 scripts/align_hls.py --track 84efde38a8eb4347
    python3 scripts/align_hls.py --json /tmp/align.json
    python3 scripts/align_hls.py --fail-over-ms 3   # exit 1 si hay desalineadas

Requiere ffmpeg y numpy (python3-numpy del contenedor).
"""

import argparse
import datetime as dt
import json
import os
import random
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile
from pathlib import Path

try:
    import numpy as np
except ImportError:
    sys.stderr.write(
        "Falta numpy. Instala python3-numpy (paquete Debian) en la imagen del contenedor.\n"
    )
    sys.exit(2)

SAMPLE_RATE = 8000
REFERENCE_OFFSET = 1000  # muestras antes de la ventana de referencia


def log(msg):
    print(msg, flush=True)


def decode(ffmpeg, playlist, start, duration=1.4):
    """Decodifica un tramo a PCM mono f32. Devuelve None si no hay audio."""
    try:
        proc = subprocess.run(
            [
                ffmpeg, "-nostdin", "-v", "error", "-threads", "1",
                "-ss", "%.3f" % start, "-t", "%.3f" % duration,
                "-i", str(playlist), "-map", "0:a:0",
                "-ac", "1", "-ar", str(SAMPLE_RATE), "-f", "f32le", "-",
            ],
            capture_output=True,
            timeout=90,
        )
    except (OSError, subprocess.TimeoutExpired):
        return None
    samples = len(proc.stdout) // 4
    if samples < SAMPLE_RATE // 2:
        return None
    return np.frombuffer(proc.stdout[: samples * 4], dtype=np.float32).astype(np.float64)


def lag_ms(a, b, max_offset_ms=80, window_ms=1000):
    """Desfase de b respecto a a en ms (positivo = b va retrasado).

    Correlacion normalizada por FFT implicita (np.correlate) sobre 1 s de
    audio a 8 kHz. Devuelve (ms, coeficiente) o None si no hay señal.
    """
    max_lag = int(max_offset_ms * SAMPLE_RATE / 1000)
    window = int(window_ms * SAMPLE_RATE / 1000)
    offset = REFERENCE_OFFSET
    if a is None or b is None:
        return None
    if len(a) < offset + window or len(b) < offset + window + max_lag:
        return None
    ref = a[offset:offset + window]
    energy = float(np.dot(ref, ref))
    if energy <= 1e-6:
        return None
    segment = b[offset - max_lag:offset + window + max_lag]
    if len(segment) < window:
        return None
    corr = np.correlate(segment, ref, mode="valid")
    cumulative = np.concatenate(([0.0], np.cumsum(segment * segment)))
    windows = cumulative[window:] - cumulative[:-window]
    normalized = corr / (np.sqrt(np.maximum(windows, 0.0) * energy) + 1e-9)
    index = int(np.argmax(normalized))
    return (index - max_lag) / SAMPLE_RATE * 1000.0, float(normalized[index])


def measure_pair(ffmpeg, top, other, duration, max_offset_ms, candidates=None):
    """Prueba varios instantes para evitar silencios o cortes de edicion."""
    if candidates is None:
        candidates = [min(30.0, max(1.0, duration * 0.2)), max(0.5, duration * 0.5), 1.0]
    for start in candidates:
        a = decode(ffmpeg, top, start)
        b = decode(ffmpeg, other, start)
        result = lag_ms(a, b, max_offset_ms)
        if result and result[1] >= 0.5:
            return result
    return None


def open_database(path):
    """Abre una copia consistente de la base (evita bloqueos y el modo WAL)."""
    temp_dir = tempfile.mkdtemp(prefix="align-hls-")
    copy = os.path.join(temp_dir, "library.sqlite")
    try:
        source = sqlite3.connect("file:%s?mode=ro" % path, uri=True, timeout=10)
        target = sqlite3.connect(copy)
        source.backup(target)
        target.close()
        source.close()
    except sqlite3.Error:
        shutil.copyfile(path, copy)
        if os.path.exists(path + "-wal"):
            shutil.copyfile(path + "-wal", copy + "-wal")
    return sqlite3.connect(copy), temp_dir


def read_variants(master_path):
    """Devuelve las calidades ordenadas de menor a mayor bitrate.

    Se usa BANDWIDTH del master (no el orden del archivo) para elegir la
    referencia; asi funciona aunque el master liste las calidades al reves.
    """
    text = master_path.read_text(encoding="utf-8", errors="replace")
    entries = []
    bandwidth = None
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


def edited_track_ids(db):
    try:
        return {row[0] for row in db.execute(
            "SELECT DISTINCT track_id FROM audio_edit_jobs WHERE state='done'"
        )}
    except sqlite3.Error:
        return set()


def worst_offset(record):
    values = [abs(v) for v in record["offsets"].values()]
    return max(values) if values else None


def format_variants(record):
    return " ".join(
        "%s:%+.1f" % (name, value) for name, value in sorted(record["offsets"].items())
    )


def print_section(title, records, threshold, show_all, list_tracks=True):
    measured = [r for r in records if worst_offset(r) is not None]
    flagged = [r for r in measured if worst_offset(r) > threshold]
    failed = [r for r in records if r["failed"]]
    log("")
    log("%s: %d pistas" % (title, len(records)))
    log("  medidas: %d | con desfase > %.1f ms: %d | sin medir: %d" % (
        len(measured), threshold, len(flagged), len(failed)))
    if measured:
        values = sorted(worst_offset(r) for r in measured)
        log("  mediana %.1f ms | p90 %.1f ms | max %.1f ms" % (
            values[len(values) // 2], values[int(len(values) * 0.9)], values[-1]))
    variable = [r for r in measured if r.get("drift") and max(r["drift"].values()) > threshold]
    if variable:
        log("  con desfase variable en el tiempo: %d" % len(variable))
    if list_tracks:
        shown = measured if show_all else flagged
        for record in sorted(shown, key=lambda r: -worst_offset(r)):
            drift = max(record.get("drift", {}).values(), default=0.0)
            mark = "  ~%.1f variable" % drift if drift and drift > threshold else ""
            log("   %+7.1f ms  n=%d  %-46s  [%s]%s" % (
                worst_offset(record), record["variants"], record["name"][:46],
                format_variants(record), mark))
        for record in failed[:40]:
            log("   SIN MEDIR  n=%d  %-52s  %s" % (
                record["variants"], record["name"][:52], ",".join(record["failed"])))


def print_variant_stats(records):
    """Resumen por calidad: cuantos desfases distintos aparecen en cada una."""
    stats = {}
    for record in records:
        for quality, offset in record["offsets"].items():
            stats.setdefault(quality, {}).setdefault(round(offset, 1), 0)
            stats[quality][round(offset, 1)] += 1
    if not stats:
        return
    log("")
    log("Desglose por calidad (desfase x numero de pistas):")
    for quality in sorted(stats, key=lambda q: int(re.sub(r"\D", "", q) or 0)):
        parts = ", ".join("%+.1f ms x %d" % (value, count)
                          for value, count in sorted(stats[quality].items()))
        log("  %-5s %s" % (quality, parts))


def main():
    parser = argparse.ArgumentParser(
        description="Mide el desfase entre las calidades HLS del catalogo (solo lectura).")
    parser.add_argument("--audio-root", default=None,
                        help="Raiz de audio/ (por defecto, el padre de este script)")
    parser.add_argument("--db", default=None,
                        help="Ruta de library.sqlite (por defecto hls_out/../database)")
    parser.add_argument("--sample", type=int, default=0,
                        help="Analizar solo N pistas aleatorias (0 = todas)")
    parser.add_argument("--seed", type=int, default=1, help="Semilla para --sample")
    parser.add_argument("--track", action="append", default=[],
                        help="Analizar solo esta pista (id); se puede repetir")
    parser.add_argument("--threshold-ms", type=float, default=3.0,
                        help="Desfase a partir del cual se considera desalineada")
    parser.add_argument("--max-offset-ms", type=float, default=80.0,
                        help="Rango maximo de busqueda del desfase")
    parser.add_argument("--all", action="store_true", help="Listar tambien las alineadas")
    parser.add_argument("--drift", action="store_true",
                        help="Medir un segundo instante para detectar desfase variable")
    parser.add_argument("--json", default=None, help="Escribir el resultado completo en JSON")
    parser.add_argument("--fail-over-ms", type=float, default=None,
                        help="Salir con codigo 1 si hay pistas por encima de este desfase")
    args = parser.parse_args()

    if not shutil.which("ffmpeg"):
        sys.stderr.write("ffmpeg no esta disponible en PATH.\n")
        return 2

    audio_root = Path(args.audio_root).resolve() if args.audio_root \
        else Path(__file__).resolve().parent.parent
    hls_root = (audio_root / "hls_out").resolve()
    if not hls_root.is_dir():
        sys.stderr.write("No encuentro hls_out en %s\n" % hls_root)
        return 2
    database = Path(args.db) if args.db else hls_root.parent / "database" / "library.sqlite"
    if not database.is_file():
        sys.stderr.write("No encuentro la base de datos en %s\n" % database)
        return 2

    db, temp_dir = open_database(str(database))
    db.row_factory = sqlite3.Row
    try:
        edited = edited_track_ids(db)
        query = "SELECT id,name,hls_path FROM tracks WHERE hls_path IS NOT NULL ORDER BY name"
        rows = db.execute(query).fetchall()
        if args.track:
            wanted = set(args.track)
            rows = [r for r in rows if r["id"] in wanted]
        if args.sample and args.sample < len(rows):
            rows = random.Random(args.seed).sample(rows, args.sample)
            rows.sort(key=lambda r: r["name"])

        log("Analizando %d pistas en %s" % (len(rows), hls_root))
        records = []
        for position, row in enumerate(rows, 1):
            base = Path(os.path.join(str(hls_root), row["hls_path"].replace("hls_out/", "", 1))).parent
            record = {
                "id": row["id"], "name": row["name"], "hls": row["hls_path"],
                "edited": row["id"] in edited, "variants": 0, "duration": 0.0,
                "offsets": {}, "drift": {}, "failed": [],
            }
            master = base / "master.m3u8"
            try:
                variants = read_variants(master)
                info = json.loads((base / "info.json").read_text(encoding="utf-8"))
                record["duration"] = float(info.get("duration_sec") or 0)
            except (OSError, ValueError):
                record["failed"].append("sin master/info")
                records.append(record)
                continue
            record["variants"] = len(variants)
            if len(variants) >= 2:
                top = base / variants[-1] / "prog.m3u8"
                for quality in variants[:-1]:
                    other = base / quality / "prog.m3u8"
                    result = measure_pair(
                        "ffmpeg", top, other, record["duration"], args.max_offset_ms)
                    if result is None:
                        record["failed"].append(quality)
                        continue
                    record["offsets"][quality] = round(result[0], 2)
                    if args.drift and record["duration"] > 40:
                        # Segundo instante: un desfase que cambia con el tiempo
                        # no se corrige recortando, hay que rehacer la escalera.
                        late = measure_pair(
                            "ffmpeg", top, other, record["duration"], args.max_offset_ms,
                            candidates=[min(record["duration"] * 0.75, record["duration"] - 5)])
                        if late:
                            record["drift"][quality] = round(abs(late[0] - result[0]), 2)
            records.append(record)
            if position % 25 == 0:
                log("  ... %d/%d" % (position, len(rows)))

        print_section("Editadas (recorte/fade)", [r for r in records if r["edited"]],
                      args.threshold_ms, args.all)
        print_section("Sin editar", [r for r in records if not r["edited"]],
                      args.threshold_ms, args.all)
        print_section("Total", records, args.threshold_ms, args.all, list_tracks=args.all)
        print_variant_stats(records)

        if args.json:
            payload = {
                "generated_at": dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds"),
                "audio_root": str(audio_root),
                "threshold_ms": args.threshold_ms,
                "tracks": records,
            }
            Path(args.json).write_text(
                json.dumps(payload, ensure_ascii=False, indent=1), encoding="utf-8")
            log("\nJSON escrito en %s" % args.json)

        if args.fail_over_ms is not None:
            exceeded = [r for r in records
                        if (worst_offset(r) is not None and worst_offset(r) > args.fail_over_ms)]
            variable = [r for r in records
                        if r.get("drift") and max(r["drift"].values()) > args.fail_over_ms]
            return 1 if (exceeded or variable) else 0
        return 0
    finally:
        db.close()
        shutil.rmtree(temp_dir, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
