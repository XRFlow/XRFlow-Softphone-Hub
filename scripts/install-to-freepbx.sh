#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="/var/www/html/admin/modules/xrflowsoftphone"
if [[ $EUID -ne 0 ]]; then
  echo "Run as root" >&2
  exit 1
fi
"$ROOT/packaging/stage-module.sh" "$DEST"
chown -R asterisk:asterisk "$DEST"
echo "Copied to $DEST — run: fwconsole ma install xrflowsoftphone && fwconsole reload"
