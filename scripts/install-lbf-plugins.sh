#!/bin/bash
# Install the OPD LBF form plugins into the running container's sites volume.
#
# interface/forms/LBF/new.php auto-includes a per-form plugin from
# OE_SITE_DIR/LBF/<formname>.plugin.php. sites/ is the Docker named volume
# (sitesvolume), NOT the host bind mount, so the authoritative plugins (kept in the
# module at oe-module-opd/lbf/) must be copied into the volume. Re-run after a
# sites-volume reset. Idempotent.

set -euo pipefail
PROJECT_ROOT=/Applications/XAMPP/xamppfiles/htdocs/openemr
COMPOSE="docker compose -f $PROJECT_ROOT/docker/development-easy/docker-compose.yml"
SRC="$PROJECT_ROOT/interface/modules/custom_modules/oe-module-opd/lbf"
DEST="/var/www/localhost/htdocs/openemr/sites/default/LBF"

$COMPOSE exec -T openemr sh -c "mkdir -p $DEST"
for f in "$SRC"/*.plugin.php; do
  name=$(basename "$f")
  $COMPOSE cp "$f" "openemr:$DEST/$name"
  echo "installed LBF plugin $name"
done

$COMPOSE exec -T openemr sh -c "chown apache:apache $DEST/*.plugin.php 2>/dev/null || true; chmod 644 $DEST/*.plugin.php"
echo "OPD LBF plugins installed into the sites volume."
