#!/usr/bin/env bash
# Pakuje wtyczkę do pliku .zip gotowego do wgrania w WordPressie.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/plugin/amp-avatar-capture/amp-avatar-capture.php" | tr -d '[:space:]')"
mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/amp-avatar-capture-$VERSION.zip"
rm -f "$ZIP"
(cd "$ROOT/plugin" && zip -qr "$ZIP" amp-avatar-capture -x '*.DS_Store')
for f in $(cd "$ROOT/plugin" && find amp-avatar-capture -name '*.php'); do php -l "$ROOT/plugin/$f" >/dev/null; done
echo "$ZIP"
