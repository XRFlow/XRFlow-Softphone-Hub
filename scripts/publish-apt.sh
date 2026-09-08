#!/bin/bash
# Publish the Hub .deb into the existing XRFlow apt repo (stable + bookworm).
# Uses the same GPG home as the desktop Softphone repo. Never prints secrets.
set -euo pipefail

DEB="${1:-}"
if [ -z "$DEB" ]; then
  echo "usage: $0 /path/to/xrflow-softphone-hub_<ver>_all.deb" >&2
  exit 1
fi
DEB="$(readlink -f "$DEB")"
if [ ! -f "$DEB" ]; then
  echo "missing deb: $DEB" >&2
  exit 1
fi

APT_DIR="${SOFTPHONE_APT_DIR:-/var/www/html/apt}"
GPG_HOME="${SOFTPHONE_APT_GNUPG:-/etc/xrflow/apt-gnupg}"
DROP="${HUB_BUILD_DIR:-/opt/xrflow/dist/softphone-hub}"
DOWNLOAD_DIR="${HUB_DOWNLOAD_DIR:-/var/www/html/downloads/xrflow-softphone-hub}"
VERSION="$(dpkg-deb -f "$DEB" Version)"
NAME="$(basename "$DEB")"

if [ ! -d "$APT_DIR/conf" ]; then
  echo "Apt repo $APT_DIR is not initialized. Run packaging/setup-softphone-downloads.sh" >&2
  exit 1
fi
if ! command -v reprepro >/dev/null 2>&1; then
  echo "reprepro is not installed" >&2
  exit 1
fi

install -d -m 2775 -o odoo -g www-data "$DROP" "$DOWNLOAD_DIR/$VERSION" "$DOWNLOAD_DIR/latest"
# Only the current version in the drop folder (same rule as desktop Softphone).
find "$DROP" -maxdepth 1 -type f -name 'xrflow-softphone-hub_*.deb' -delete
cp -f "$DEB" "$DROP/$NAME"
cp -f "$DEB" "$DOWNLOAD_DIR/$VERSION/$NAME"
cp -f "$DEB" "$DOWNLOAD_DIR/latest/xrflow-softphone-hub_all.deb"
chmod 0644 "$DROP/$NAME" "$DOWNLOAD_DIR/$VERSION/$NAME" "$DOWNLOAD_DIR/latest/xrflow-softphone-hub_all.deb"
chown odoo:www-data "$DROP/$NAME" "$DOWNLOAD_DIR/$VERSION/$NAME" "$DOWNLOAD_DIR/latest/xrflow-softphone-hub_all.deb"
# odoo cannot read /root; include from the drop folder.
PUBLISH_DEB="$DROP/$NAME"

export GNUPGHOME="$GPG_HOME"
run_reprepro() {
  local dist="$1"
  if id odoo >/dev/null 2>&1; then
    sudo -u odoo -H env GNUPGHOME="$GPG_HOME" HOME="/var/lib/odoo" \
      reprepro -b "$APT_DIR" includedeb "$dist" "$PUBLISH_DEB"
  else
    reprepro -b "$APT_DIR" includedeb "$dist" "$PUBLISH_DEB"
  fi
}

run_reprepro stable
if grep -q '^Codename: bookworm$' "$APT_DIR/conf/distributions"; then
  run_reprepro bookworm
fi

echo "published $NAME version=$VERSION apt=$APT_DIR downloads=$DOWNLOAD_DIR/$VERSION"
