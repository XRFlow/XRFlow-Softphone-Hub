#!/bin/bash
# Stage the FreePBX module and build dist/xrflowsoftphone-<version>.tgz
# Use --tarball-only to re-pack after sign.php wrote module.sig into the stage dir.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="${HUB_VERSION:-}"
if [ -z "$VERSION" ]; then
  VERSION="$(sed -n 's/.*<version>\([^<]*\)<\/version>.*/\1/p' "$ROOT/module.xml" | head -1)"
fi
if [ -z "$VERSION" ]; then
  echo "Could not read version from module.xml" >&2
  exit 1
fi

OUT_DIR="${HUB_MODULE_OUT:-$ROOT/dist}"
STAGE="${OUT_DIR}/module/xrflowsoftphone"
TARBALL="${OUT_DIR}/xrflowsoftphone-${VERSION}.tgz"

install -d -m 0755 "$OUT_DIR/module"

if [ "${1:-}" != "--tarball-only" ]; then
  "$ROOT/packaging/stage-module.sh" "$STAGE"
fi

if [ ! -f "$STAGE/module.xml" ]; then
  echo "Stage is empty. Run without --tarball-only first." >&2
  exit 1
fi

tar -C "$OUT_DIR/module" --owner=0 --group=0 -czf "$TARBALL" xrflowsoftphone
# Keep only the current tarball name
find "$OUT_DIR" -maxdepth 1 -type f -name 'xrflowsoftphone-*.tgz' ! -name "$(basename "$TARBALL")" -delete
echo "$TARBALL"
tar -tzf "$TARBALL" | head -40
