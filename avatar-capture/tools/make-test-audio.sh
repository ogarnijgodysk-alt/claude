#!/usr/bin/env bash
# Generuje testowe pliki z pytaniami (głos syntetyczny espeak-ng).
# W wersji docelowej zastąpimy je naturalnym głosem lektora.
set -euo pipefail
OUT="$(cd "$(dirname "$0")/.." && pwd)/plugin/amp-avatar-capture/assets/audio"
mkdir -p "$OUT"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
i=1
while IFS= read -r line; do
  [ -z "$line" ] && continue
  espeak-ng -v en-us+f3 -s 150 -w "$TMP/q$i.wav" "$line"
  ffmpeg -loglevel error -y -i "$TMP/q$i.wav" -ac 1 -ar 44100 -b:a 64k "$OUT/q$i.mp3"
  i=$((i+1))
done <<'Q'
What do you do for work?
What do you like to do on weekends?
Where would you love to travel next, and why?
Tell me about something you are proud of.
Q
ls -la "$OUT"
