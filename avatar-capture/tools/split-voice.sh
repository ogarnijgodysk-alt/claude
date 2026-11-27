#!/usr/bin/env bash
# Dzieli jedno nagranie lektora (ElevenLabs, zdania z tools/voice-lines.txt
# rozdzielone tagiem [long pause]) na osobne, wyrównane głośnością pliki mp3.
# Użycie: tools/split-voice.sh master.mp3
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$1"
OUT="$ROOT/plugin/amp-avatar-capture/assets/audio"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
ffmpeg -v info -i "$SRC" -af silencedetect=noise=-45dB:d=0.9 -f null - 2>&1 | grep -E "silence_(start|end)" > "$TMP/sil.txt"
TOTAL="$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$SRC")"
python3 - "$TMP/sil.txt" "$TOTAL" "$SRC" "$OUT" "$ROOT/tools/voice-lines.txt" <<'PY'
import sys, re, subprocess, os
sil, total, src, out, lines = sys.argv[1], float(sys.argv[2]), sys.argv[3], sys.argv[4], sys.argv[5]
names = [l.split('|')[0] for l in open(lines, encoding='utf-8') if l.strip()]
starts = [float(m.group(1)) for m in (re.search(r'silence_start: ([\d.]+)', l) for l in open(sil)) if m]
ends = [float(m.group(1)) for m in (re.search(r'silence_end: ([\d.]+)', l) for l in open(sil)) if m]
segs, prev = [], 0.0
for s, e in zip(starts, ends):
    if s - prev > 0.15: segs.append((prev, s))
    prev = e
if total - prev > 0.15: segs.append((prev, total))
if len(segs) != len(names): sys.exit(f'found {len(segs)} segments, expected {len(names)}')
os.makedirs(out, exist_ok=True)
for (a, b), n in zip(segs, names):
    a2, b2 = max(0, a - 0.06), min(total, b + 0.12)
    subprocess.run(['ffmpeg', '-v', 'error', '-y', '-ss', f'{a2:.3f}', '-to', f'{b2:.3f}', '-i', src,
                    '-af', 'loudnorm=I=-14:TP=-1.0:LRA=7,aresample=44100', '-ac', '1', '-b:a', '96k',
                    os.path.join(out, n + '.mp3')], check=True)
    print(f'{n:16s} {b2 - a2:5.2f}s')
PY
