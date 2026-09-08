#!/bin/bash
# Fail closed if this host cannot run the Hub (FreePBX 17 / Debian 12).
# Used as DEBIAN/preinst and again from postinst. Do not start OpenVPN.
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

# Probe actual PHP APIs. Do not grep php -m: PHP 8.2 compiles json into core
# and Debian 12 has no php8.2-json package.
need_php() {
  local what="$1"
  local hint="$2"
  local code="$3"
  if ! php -r "$code" >/dev/null 2>&1; then
    err "PHP is missing ${what}."
    if [ -n "$hint" ]; then
      err "$hint"
    fi
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

need_php "curl (curl_init)" "Install php8.2-curl (or php-curl)." 'exit(function_exists("curl_init")?0:1);'
need_php "JSON (json_encode)" "JSON is built into PHP 8; no php-json package. Check that /usr/bin/php is the FreePBX CLI." 'exit(function_exists("json_encode")?0:1);'
need_php "PDO" "Install php8.2-mysql / php-mysql (provides PDO)." 'exit(class_exists("PDO")?0:1);'
need_php "PDO mysql driver" "Install php8.2-mysql (or php-mysql)." 'exit(in_array("mysql", PDO::getAvailableDrivers(), true)?0:1);'
need_php "mbstring" "Install php8.2-mbstring (or php-mbstring)." 'exit(function_exists("mb_strlen")?0:1);'
need_php "XML/SimpleXML" "Install php8.2-xml (or php-xml)." 'exit((function_exists("simplexml_load_string")||extension_loaded("xml")||extension_loaded("libxml"))?0:1);'

if ! command -v apache2 >/dev/null 2>&1 && ! command -v apache2ctl >/dev/null 2>&1 && ! command -v httpd >/dev/null 2>&1; then
  err "Apache is required (package apache2) so /xrflow-hub can be served."
  exit 1
fi

if ! command -v rsync >/dev/null 2>&1; then
  err "rsync is required to install the FreePBX module tree."
  exit 1
fi
