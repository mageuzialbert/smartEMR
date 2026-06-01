#!/bin/bash
# Phase 1.3 — patch php.ini for OpenEMR + Tanzania defaults.
# Must run with sudo. Idempotent: skips already-patched lines.
# Backup is made by the caller (or with --backup flag).

set -euo pipefail

INI=/Applications/XAMPP/xamppfiles/etc/php.ini

if [[ $EUID -ne 0 ]]; then
  echo "Re-run with sudo: sudo $0" >&2
  exit 1
fi

if [[ "${1:-}" == "--backup" ]]; then
  cp "$INI" "${INI}.bak.$(date +%Y%m%d_%H%M%S)"
  echo "Backup: ${INI}.bak.$(date +%Y%m%d_%H%M%S)"
fi

/usr/bin/sed -i '' \
  -e 's|^post_max_size=40M|post_max_size=64M|' \
  -e 's|^upload_max_filesize=40M|upload_max_filesize=64M|' \
  -e 's|^date.timezone=Europe/Berlin|date.timezone=Africa/Dar_es_Salaam|' \
  "$INI"

echo "Patched values:"
grep -nE '^(post_max_size|upload_max_filesize|date\.timezone)\s*=' "$INI"

echo "Reloading Apache..."
/Applications/XAMPP/xamppfiles/xampp reloadapache
echo "OK"
