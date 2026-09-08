#!/bin/bash
# Copy only the FreePBX module payload into a destination directory.
# Excludes packaging, git, dist, signing docs, and private keys.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${1:-}"
if [ -z "$DEST" ]; then
  echo "usage: $0 <dest-dir>" >&2
  exit 1
fi

rm -rf "$DEST"
mkdir -p "$DEST/views" "$DEST/public" "$DEST/docs"

install -m 0644 \
  "$ROOT/module.xml" \
  "$ROOT/LICENSE" \
  "$ROOT/COPYRIGHT" \
  "$ROOT/README.md" \
  "$ROOT/Xrflowsoftphone.class.php" \
  "$ROOT/page.xrflowsoftphone.php" \
  "$DEST/"

install -m 0644 "$ROOT"/views/*.php "$DEST/views/"
install -m 0644 "$ROOT/public/index.php" "$DEST/public/index.php"
install -m 0644 "$ROOT/docs/hub-enroll.schema.json" "$DEST/docs/hub-enroll.schema.json"

# Never stage signatures or keys even if they exist in the working tree.
rm -f "$DEST/module.sig" "$DEST"/*.gpg "$DEST"/*.asc
