#!/bin/bash
# Install the OPD role menus (main + patient-chart) into the running container's
# sites volume.
#
# The sites/ directory is a Docker named volume (sitesvolume), NOT the host bind
# mount, and sites/default/documents is gitignored. The authoritative copies live
# in the module at interface/modules/custom_modules/oe-module-opd/menus/; this
# script copies them into OE_SITE_DIR/documents/custom_menus/ (main menus, loaded
# by MainMenuRole) and .../custom_menus/patient_menus/ (patient-chart menus, loaded
# by PatientMenuRole). Re-run after a sites-volume reset. Idempotent.

set -euo pipefail
PROJECT_ROOT=/Applications/XAMPP/xamppfiles/htdocs/openemr
COMPOSE="docker compose -f $PROJECT_ROOT/docker/development-easy/docker-compose.yml"
SRC="$PROJECT_ROOT/interface/modules/custom_modules/oe-module-opd/menus"
DEST="/var/www/localhost/htdocs/openemr/sites/default/documents/custom_menus"

# Main (left) menus
for f in "$SRC"/opd_*.json; do
  name=$(basename "$f")
  $COMPOSE cp "$f" "openemr:$DEST/$name"
  echo "installed main menu $name"
done

# Patient-chart menus
$COMPOSE exec -T openemr sh -c "mkdir -p $DEST/patient_menus"
for f in "$SRC"/patient_menus/opd_*.json; do
  name=$(basename "$f")
  $COMPOSE cp "$f" "openemr:$DEST/patient_menus/$name"
  echo "installed patient menu $name"
done

# Ensure readable by the web server user.
$COMPOSE exec -T openemr sh -c "chown apache:apache $DEST/opd_*.json $DEST/patient_menus/opd_*.json 2>/dev/null || true; chmod 644 $DEST/opd_*.json $DEST/patient_menus/opd_*.json"
echo "OPD role menus (main + patient) installed into the sites volume."
