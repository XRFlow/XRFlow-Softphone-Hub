#!/bin/bash
# Sign the staged module with FreePBX devtools/sign.php.
# After Sangoma has signed your key, omit KEYID and let sign.php pick it.
# Never commit the secret key. Do not commit module.sig until the key is Sangoma-signed.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEVTOOLS="${FREEPBX_DEVTOOLS:-/usr/src/devtools}"
STAGE="${HUB_MODULE_OUT:-$ROOT/dist}/module/xrflowsoftphone"
KEYID="${1:-${FREEPBX_GPG_KEY:-}}"

if [ ! -x "$DEVTOOLS/sign.php" ] && [ ! -f "$DEVTOOLS/sign.php" ]; then
  echo "FreePBX devtools sign.php not found at $DEVTOOLS" >&2
  echo "git clone https://github.com/FreePBX/devtools $DEVTOOLS" >&2
  exit 1
fi

if [ ! -f "$STAGE/module.xml" ]; then
  "$ROOT/packaging/pack-module.sh"
fi

if [ -n "$KEYID" ]; then
  php "$DEVTOOLS/sign.php" "$STAGE" "$KEYID"
else
  php "$DEVTOOLS/sign.php" "$STAGE"
fi

"$ROOT/packaging/pack-module.sh" --tarball-only
echo "Signed $STAGE/module.sig"
echo "Do not commit module.sig until the key is signed by Sangoma."
