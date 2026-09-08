#!/bin/bash
# Fail closed if this host cannot run the Hub (FreePBX 17 / Debian 12).
# Used by preinst and postinst. Do not start OpenVPN.
set -euo pipefail

err() { echo "xrflow-softphone-hub: $*" >&2; }

need_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    err "missing command '$1'."
    err "This package is for FreePBX 17 / PBXact on Debian 12. Install FreePBX first, then:"
    err "  apt update && apt install xrflow-softphone-hub"
    exit 1
  fi
}

need_php_ext() {
  local ext="$1"
  if ! php -m 2>/dev/null | grep -qi "^${ext}$"; then
    err "PHP extension '${ext}' is not loaded."
    err "On Debian 12 / FreePBX 17 install: php8.2-${ext} (or the matching php-${ext} package)."
    exit 1
  fi
}

framework_major() {
  local xml="/var/www/html/admin/modules/framework/module.xml"
  if [ -r "$xml" ]; then
    sed -n 's/.*<version>\([0-9][0-9]*\)\..*/\1/p' "$xml" | head -1
    return 0
  fi
  if command -v fwconsole >/dev/null 2>&1; then
    fwconsole -V 2>/dev/null | sed -n 's/.*\b\([0-9][0-9]*\)\.[0-9].*/\1/p' | head -1
    return 0
  fi
  echo ""
}

need_cmd php
need_cmd fwconsole
if [ ! -d /var/www/html/admin/modules ]; then
  err "FreePBX modules directory /var/www/html/admin/modules is missing."
  exit 1
fi
if [ ! -r /etc/freepbx.conf ] && [ ! -r /etc/asterisk/freepbx.conf ]; then
  err "FreePBX config (/etc/freepbx.conf) not found."
  exit 1
fi

MAJOR="$(framework_major)"
if [ -z "$MAJOR" ]; then
  err "Could not read FreePBX framework version."
  exit 1
fi
if [ "$MAJOR" -lt 17 ]; then
  err "FreePBX ${MAJOR} is too old. This Hub requires FreePBX 17 / PBXact 17."
  exit 1
fi

need_php_ext curl
need_php_ext json
need_php_ext pdo
need_php_ext pdo_mysql
need_php_ext mbstring
need_php_ext xml

if ! command -v apache2 >/dev/null 2>&1 && ! command -v apache2ctl >/dev/null 2>&1 && ! command -v httpd >/dev/null 2>&1; then
  err "Apache is required (package apache2) so /xrflow-hub can be served."
  exit 1
fi

if ! command -v rsync >/dev/null 2>&1; then
  err "rsync is required to install the FreePBX module tree."
  exit 1
fi
