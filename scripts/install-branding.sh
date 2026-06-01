#!/usr/bin/env bash
# Install smartEMR branding image assets into the running openemr container.
# Logos/background live in the 'sitesvolume' named volume (not the repo bind-mount),
# so they must be written inside the container. Re-run after a DB/volume reset.
#
#   ./scripts/install-branding.sh
#
# Source assets (in repo): branding/ipab-logo-src.png, branding/login-bg.svg
# Uses the container's ImageMagick to make the logo background transparent and to
# rasterise the background SVG. Idempotent.
set -euo pipefail

cd "$(dirname "$0")/.."
COMPOSE="docker compose -f docker/development-easy/docker-compose.yml"
APP=/var/www/localhost/htdocs/openemr
SITEIMG="$APP/sites/default/images"
LOGOS="$SITEIMG/logos/core"

# 1. Stage source assets into the bind-mounted project dir (container can read them there).
#    branding/ is already on the bind mount at $APP/branding.

echo "==> creating logo directories in the sites volume"
$COMPOSE exec -T openemr sh -lc "mkdir -p '$LOGOS/login/primary' '$LOGOS/menu/primary' '$LOGOS/favicon' '$SITEIMG'"

echo "==> making the iPAB logo background transparent (login + menu)"
$COMPOSE exec -T openemr sh -lc "
  convert '$APP/branding/ipab-logo-src.png' -fuzz 18% -transparent white -trim +repage '$LOGOS/login/primary/logo.png' &&
  cp '$LOGOS/login/primary/logo.png' '$LOGOS/menu/primary/logo.png' &&
  convert '$LOGOS/login/primary/logo.png' -background none -define icon:auto-resize=16,32,48,64 '$LOGOS/favicon/favicon.ico' &&
  rm -f '$LOGOS/favicon/logo.png'
"

echo "==> installing the medical login background (SVG, rendered by the browser)"
$COMPOSE exec -T openemr sh -lc "cp '$APP/branding/login-bg.svg' '$SITEIMG/login-bg.svg'"

echo "==> fixing ownership/permissions"
$COMPOSE exec -T openemr sh -lc "chown -R apache:apache '$LOGOS' '$SITEIMG/login-bg.svg' 2>/dev/null || true; chmod -R a+r '$LOGOS' '$SITEIMG/login-bg.svg'"

echo "==> clearing twig/smarty cache so template edits take effect"
$COMPOSE exec -T openemr sh -lc "rm -rf '$APP/sites/default/documents/smarty/main'/* 2>/dev/null || true; find '$APP'/ -type d -name 'twig' -path '*cache*' -exec rm -rf {} + 2>/dev/null || true"

echo "==> done. Logos:"
$COMPOSE exec -T openemr sh -lc "ls -la '$LOGOS/login/primary' '$LOGOS/menu/primary' '$SITEIMG/login-bg.svg'"
