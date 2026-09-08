#!/bin/bash
# Generate a dedicated RSA-4096 GPG key for FreePBX module signing.
# This is NOT the apt-repo key. Do not commit the secret key.
set -euo pipefail

NAME="${FREEPBX_GPG_NAME:-XRFlow}"
EMAIL="${FREEPBX_GPG_EMAIL:-code@xrflows.com}"
COMMENT="${FREEPBX_GPG_COMMENT:-XRFlow FreePBX Module Signing Key}"
EXPIRE="${FREEPBX_GPG_EXPIRE:-2y}"

if ! command -v gpg >/dev/null 2>&1; then
  echo "gpg is required" >&2
  exit 1
fi

echo "About to generate a module-signing key:"
echo "  Name:    $NAME"
echo "  Email:   $EMAIL"
echo "  Comment: $COMMENT"
echo "  Expire:  $EXPIRE"
echo
echo "Sangoma's walkthrough allows an empty passphrase. Prefer a passphrase in a secret store."
echo "This script does not print or save the secret key. Export a backup yourself."
echo

gpg --batch --status-fd 2 --pinentry-mode loopback --passphrase "${FREEPBX_GPG_PASSPHRASE:-}" \
  --quick-generate-key "$NAME ($COMMENT) <$EMAIL>" rsa4096 sign "$EXPIRE"

echo
echo "Public keys:"
gpg --list-keys "$EMAIL"
echo
echo "Send the public key (replace KEYID):"
echo "  gpg --keyserver hkp://keyserver.ubuntu.com:80 --send-keys KEYID"
echo "Then follow docs/SANGOMA_SIGNING.md and email code@sangoma.com."
