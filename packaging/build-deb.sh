#!/bin/bash
# Build an Architecture: all .deb for FreePBX 17 / Debian 12 (bookworm).
# Output goes to dist/ — never commit the binary.
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

PKG="xrflow-softphone-hub"
ARCH="all"
DEB="${PKG}_${VERSION}_${ARCH}.deb"
OUT_DIR="${HUB_DEB_OUT:-$ROOT/dist}"
STAGE="$(mktemp -d)"
cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

install -d -m 0755 \
  "$STAGE/DEBIAN" \
  "$STAGE/usr/share/${PKG}/module" \
  "$STAGE/usr/share/doc/${PKG}" \
  "$STAGE/etc/apache2/conf-available"

# Module payload (no git, no packaging, no built debs)
tar -C "$ROOT" --exclude-vcs --exclude='./packaging' --exclude='./dist' --exclude='./.git' \
  -cf - . | tar -C "$STAGE/usr/share/${PKG}/module" -xf -

install -m 0644 "$ROOT/packaging/apache/xrflow-softphone-hub.conf" \
  "$STAGE/etc/apache2/conf-available/xrflow-softphone-hub.conf"
install -m 0644 "$ROOT/LICENSE" "$STAGE/usr/share/doc/${PKG}/copyright"
printf '%s\n' "xrflow-softphone-hub (${VERSION}) bookworm; urgency=medium" "" \
  "  * FreePBX 17 / Debian 12 Hub module." "" \
  " -- XRFlow <support@xrflows.com>  $(date -Ru)" \
  > "$STAGE/usr/share/doc/${PKG}/changelog"
gzip -9n "$STAGE/usr/share/doc/${PKG}/changelog"

SIZE="$(du -sk "$STAGE" | awk '{print $1}')"

cat >"$STAGE/DEBIAN/control" <<EOF
Package: ${PKG}
Version: ${VERSION}
Section: comm
Priority: optional
Architecture: ${ARCH}
Maintainer: XRFlow <support@xrflows.com>
Homepage: https://xrflows.com/softphone
Installed-Size: ${SIZE}
Depends: php-cli | php8.2-cli | php8.1-cli
Recommends: rsync, apache2
Description: XRFlow Softphone Hub for FreePBX 17 (Debian 12)
 FreePBX/PBXact module for one-button XRFlow Softphone enroll, WebRTC
 repair, fleet administration, and Company Presence proxy.
 .
 Installs into /var/www/html/admin/modules/xrflowsoftphone and enables
 /xrflow-hub on Apache. Requires FreePBX 17 on Debian 12 (bookworm).
 Desktop seats (xrflow_softphone) are a separate license.
 .
 Does not rewrite System Admin OpenVPN remotes.
EOF

install -m 0755 "$ROOT/packaging/debian/postinst" "$STAGE/DEBIAN/postinst"
install -m 0755 "$ROOT/packaging/debian/prerm" "$STAGE/DEBIAN/prerm"
install -m 0755 "$ROOT/packaging/debian/postrm" "$STAGE/DEBIAN/postrm"
printf '%s\n' "/etc/apache2/conf-available/xrflow-softphone-hub.conf" >"$STAGE/DEBIAN/conffiles"

# Debian 12 dpkg-deb supports --root-owner-group
install -d -m 0755 "$OUT_DIR"
dpkg-deb --root-owner-group --build "$STAGE" "$OUT_DIR/$DEB" >/dev/null
# Keep only the current version in dist/
find "$OUT_DIR" -maxdepth 1 -type f -name "${PKG}_*.deb" ! -name "$DEB" -delete
echo "$OUT_DIR/$DEB"
dpkg-deb -I "$OUT_DIR/$DEB"
