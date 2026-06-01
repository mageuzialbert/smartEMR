#!/bin/bash
# OpenEMR daily backup — Docker dev-easy stack.
# Backs up the MariaDB database and the `sitesvolume` (uploads, generated PDFs).
# Reads the DB password from the project's SECRETS.txt so this script is safe
# to keep in git (it never embeds the password).

set -euo pipefail

PROJECT_ROOT=/Applications/XAMPP/xamppfiles/htdocs/openemr
COMPOSE_DIR="$PROJECT_ROOT/docker/development-easy"
SECRETS="$PROJECT_ROOT/SECRETS.txt"

if [[ ! -f "$SECRETS" ]]; then
  echo "ERROR: $SECRETS not found" >&2
  exit 1
fi

DB_USER="openemr"
DB_PASS=$(awk '/MariaDB openemr-user password:/ {print $NF}' "$SECRETS")
DB_NAME="openemr"

BACKUP_ROOT="$HOME/openemr-backups"
TIMESTAMP=$(date +%Y-%m-%d_%H%M)
DEST="$BACKUP_ROOT/$TIMESTAMP"
mkdir -p "$DEST"

cd "$COMPOSE_DIR"

# 1. DB dump (single-transaction = consistent snapshot for InnoDB)
echo "[$(date '+%H:%M:%S')] Dumping DB ..."
docker compose exec -T mysql mariadb-dump \
  -u "$DB_USER" -p"$DB_PASS" \
  --single-transaction --quick --add-drop-table \
  "$DB_NAME" > "$DEST/openemr_db.sql"

DB_SIZE=$(wc -c < "$DEST/openemr_db.sql")
echo "  DB dump: $DB_SIZE bytes"

# 2. Sites volume (documents, generated PDFs, smarty templates)
echo "[$(date '+%H:%M:%S')] Tarring sites volume ..."
docker run --rm \
  -v development-easy_sitesvolume:/sites:ro \
  -v "$DEST":/backup \
  alpine tar -czf /backup/sites.tar.gz -C /sites .

SITES_SIZE=$(wc -c < "$DEST/sites.tar.gz")
echo "  sites.tar.gz: $SITES_SIZE bytes"

# 3. Bundle the docker-compose files + .env-less reference for restore docs
cp -p "$COMPOSE_DIR/docker-compose.yml"          "$DEST/"
cp -p "$COMPOSE_DIR/docker-compose.override.yml" "$DEST/"

# 4. Rotate: keep last 14 days
find "$BACKUP_ROOT" -maxdepth 1 -type d -mtime +14 -exec rm -rf {} \;

echo "[$(date '+%H:%M:%S')] Backup complete: $DEST"
