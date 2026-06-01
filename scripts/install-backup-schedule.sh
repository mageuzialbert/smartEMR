#!/bin/bash
# Installs the launchd plist that runs backup.sh nightly at 23:30.
# Safe to re-run; reloads if already installed.

set -euo pipefail

PLIST_SRC=/Applications/XAMPP/xamppfiles/htdocs/openemr/scripts/com.openemr.backup.plist
PLIST_DST=$HOME/Library/LaunchAgents/com.openemr.backup.plist

mkdir -p "$(dirname "$PLIST_DST")"
cp -p "$PLIST_SRC" "$PLIST_DST"

# Unload first (ignore failures if not loaded)
launchctl unload "$PLIST_DST" 2>/dev/null || true
launchctl load "$PLIST_DST"

echo "Installed: $PLIST_DST"
echo "Schedule:  daily at 23:30"
echo
echo "Trigger manually with:   launchctl start com.openemr.backup"
echo "Log:                     /tmp/openemr-backup.log"
echo "Errors:                  /tmp/openemr-backup.err"
