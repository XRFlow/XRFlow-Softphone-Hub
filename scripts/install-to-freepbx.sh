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
if [[ -d /etc/apache2/conf-available ]]; then
  install -m 0644 "$ROOT/packaging/apache/xrflow-softphone-hub.conf" /etc/apache2/conf-available/xrflow-softphone-hub.conf
  if command -v a2enconf >/dev/null 2>&1; then
    a2enconf xrflow-softphone-hub >/dev/null 2>&1 || true
  fi
  if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet apache2 2>/dev/null; then
    systemctl reload apache2 || true
  elif command -v apache2ctl >/dev/null 2>&1; then
    apache2ctl graceful || true
  fi
fi
echo "Copied to $DEST — run: fwconsole ma install xrflowsoftphone && fwconsole reload"
