#!/bin/bash
# OpenEMR restore — Docker dev-easy stack.
# Restores a backup produced by scripts/backup.sh (openemr_db.sql + sites.tar.gz)
# into a *fresh* stack. Intended for migrating to a new host (e.g. the VPS).
#
# Prerequisites on the target host:
#   - Docker + Compose installed
#   - The project repo cloned (this script lives in scripts/)
#   - SECRETS.txt and docker/development-easy/.env copied over (out of band, NOT git),
#     using the SAME credentials as the source host, because the restored sites
#     volume contains sqlconf.php which embeds the openemr DB-user password.
#
# Usage:
#   ./scripts/restore.sh /path/to/backup-dir
# where backup-dir contains openemr_db.sql and sites.tar.gz.

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_DIR="$PROJECT_ROOT/docker/development-easy"
SECRETS="$PROJECT_ROOT/SECRETS.txt"

BACKUP_DIR="${1:-}"
if [[ -z "$BACKUP_DIR" || ! -d "$BACKUP_DIR" ]]; then
  echo "Usage: $0 /path/to/backup-dir   (must contain openemr_db.sql + sites.tar.gz)" >&2
  exit 1
fi
DB_DUMP="$BACKUP_DIR/openemr_db.sql"
SITES_TAR="$BACKUP_DIR/sites.tar.gz"
[[ -f "$DB_DUMP"   ]] || { echo "ERROR: $DB_DUMP not found"   >&2; exit 1; }
[[ -f "$SITES_TAR" ]] || { echo "ERROR: $SITES_TAR not found" >&2; exit 1; }
[[ -f "$SECRETS"   ]] || { echo "ERROR: $SECRETS not found (copy it over first)" >&2; exit 1; }

ROOT_PASS=$(awk '/MariaDB root password:/ {print $NF}' "$SECRETS")
[[ -n "$ROOT_PASS" ]] || { echo "ERROR: could not read MariaDB root password from SECRETS.txt" >&2; exit 1; }

# The sites volume name is <compose-project>_sitesvolume. Default project name is
# the compose dir basename ("development-easy"); override with COMPOSE_PROJECT_NAME.
PROJECT_NAME="${COMPOSE_PROJECT_NAME:-development-easy}"
SITES_VOL="${PROJECT_NAME}_sitesvolume"

cd "$COMPOSE_DIR"

echo "=== This will OVERWRITE the openemr DB and the '$SITES_VOL' volume on THIS host."
read -r -p "Type 'restore' to proceed: " confirm
[[ "$confirm" == "restore" ]] || { echo "Aborted."; exit 1; }

# 1. Bring up only the DB (initializes root/openemr users from .env on first boot)
echo "[1/4] Starting mysql ..."
docker compose up -d mysql
echo "      waiting for mysql to accept connections ..."
until docker compose exec -T mysql mariadb -u root -p"$ROOT_PASS" -e 'SELECT 1' >/dev/null 2>&1; do
  sleep 3
done

# 2. Load the DB dump
echo "[2/4] Loading DB dump ($(wc -c < "$DB_DUMP") bytes) ..."
docker compose exec -T mysql mariadb -u root -p"$ROOT_PASS" openemr < "$DB_DUMP"

# 3. Repopulate the sites volume from the tar (creates the volume if absent)
echo "[3/4] Restoring sites volume '$SITES_VOL' ..."
docker volume create "$SITES_VOL" >/dev/null
docker run --rm \
  -v "$SITES_VOL":/sites \
  -v "$BACKUP_DIR":/backup:ro \
  alpine sh -c "rm -rf /sites/* && tar -xzf /backup/sites.tar.gz -C /sites"

# 4. Bring the rest of the stack up. sqlconf.php (restored above) marks the install
#    as configured, so the openemr container skips its auto-installer.
echo "[4/4] Starting the full stack ..."
docker compose up -d

echo "Restore complete. First boot of the openemr container still runs composer/npm"
echo "and may take many minutes; watch:  docker compose logs -f openemr"
