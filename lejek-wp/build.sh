#!/usr/bin/env bash
# Buduje plik ZIP wtyczki do wgrania w WordPress (Wtyczki → Dodaj nową → Wyślij wtyczkę na serwer).
set -euo pipefail
cd "$(dirname "$0")"
VERSION=$(grep -m1 "Version:" lejek/lejek.php | sed -E 's/.*Version:[[:space:]]*//')
mkdir -p dist
rm -f "dist/lejek-${VERSION}.zip"
zip -qr "dist/lejek-${VERSION}.zip" lejek -x '*.DS_Store'
echo "dist/lejek-${VERSION}.zip"
