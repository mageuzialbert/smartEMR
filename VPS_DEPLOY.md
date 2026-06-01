# smartEMR — VPS deployment runbook

For the **agent running on the VPS**. Deploys the smartEMR (OpenEMR 8.0.0) OPD pilot,
migrating the live data from the source host. Temporary pilot — fronted by CloudPanel
(reverse proxy + Let's Encrypt TLS + HTTP Basic Auth).

| Parameter | Value |
|---|---|
| Domain | `https://smartemr.ipab.co.tz/` |
| Code | `https://github.com/mageuzialbert/smartEMR.git` (private) |
| Stack | OpenEMR dev-easy Docker stack (`docker/development-easy/`) |
| DB name | `smartemr` |
| DB user | `smartemr` |
| App URL on host (proxy target) | `http://127.0.0.1:8300` |

## What arrives how

- **Code** → `git clone` from GitHub. Contains the custom OPD module, branding,
  role menus, seed scripts, and the three compose files. **No secrets, no data.**
- **Data + secrets** → transferred **out of band via `scp`** (NEVER via GitHub,
  because they contain the DB password and patient data):
  - `smartemr_db.sql` (or `.gz`) — the MariaDB dump
  - `sites.tar.gz` — the sites volume (documents, branding assets, installed menus,
    and the live `sqlconf.php`)
  - the source host's `SECRETS.txt` (for the OpenEMR **admin** password = line `[3]`)

> The migrated dump is portable (no `CREATE DATABASE`/`USE`), so it loads into a DB
> named `smartemr`. The dump carries the **admin** login (bcrypt) — after restore,
> the admin password equals **line [3] of the source `SECRETS.txt`**.

---

## Step 0 — Prerequisites

```bash
# Docker Engine + Compose plugin (Debian/Ubuntu; skip if already installed)
docker --version && docker compose version || {
  curl -fsSL https://get.docker.com | sh
}
```
Confirm DNS: `smartemr.ipab.co.tz` → this VPS's public IP. CloudPanel installed.

## Step 1 — Clone the code

```bash
sudo mkdir -p /opt && cd /opt
git clone https://github.com/mageuzialbert/smartEMR.git smartemr
cd /opt/smartemr
```

## Step 2 — Receive data + secrets (run on the SOURCE host, or scp into the VPS)

Place these on the VPS at `/opt/smartemr/deploy/` (gitignored):

```bash
mkdir -p /opt/smartemr/deploy
# from the source host:
#   scp ~/openemr-backups/<latest>/openemr_db.sql   vps:/opt/smartemr/deploy/smartemr_db.sql
#   scp ~/openemr-backups/<latest>/sites.tar.gz      vps:/opt/smartemr/deploy/sites.tar.gz
#   scp <source>/SECRETS.txt                          vps:/opt/smartemr/SECRETS.txt   # admin pw reference only
chmod 600 /opt/smartemr/SECRETS.txt
```

## Step 3 — Create the VPS `.env`

```bash
cd /opt/smartemr/docker/development-easy
cp .env.vps.example .env
# generate strong values:
ROOT_PASS=$(openssl rand -base64 24)
DB_PASS=$(openssl rand -base64 24)
sed -i "s|^MYSQL_ROOT_PASS=.*|MYSQL_ROOT_PASS=${ROOT_PASS}|"   .env
sed -i "s|^MYSQL_USER_PASS=.*|MYSQL_USER_PASS=${DB_PASS}|"     .env
sed -i "s|^OE_ADMIN_PASS=.*|OE_ADMIN_PASS=$(openssl rand -base64 24)|" .env
chmod 600 .env
# remember DB_PASS — it must also go into sqlconf.php in Step 6
echo "DB_PASS=$DB_PASS"
```

## Step 4 — Set the public domain in the VPS overlay

Edit `docker/development-easy/docker-compose.vps.yml`, set:
```yaml
OPENEMR_SETTING_site_addr_oath: "https://smartemr.ipab.co.tz"
```
(Already templated there — just confirm the domain.)

Define the compose invocation once:
```bash
cd /opt/smartemr/docker/development-easy
dc() { docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.vps.yml "$@"; }
```

## Step 5 — Start MariaDB only, create the `smartemr` DB + user, load the dump

```bash
dc up -d mysql
# wait for it
until dc exec -T mysql mariadb -u root -p"${ROOT_PASS}" -e 'SELECT 1' >/dev/null 2>&1; do sleep 3; done

dc exec -T mysql mariadb -u root -p"${ROOT_PASS}" <<SQL
CREATE DATABASE IF NOT EXISTS smartemr CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS 'smartemr'@'%' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON smartemr.* TO 'smartemr'@'%';
FLUSH PRIVILEGES;
SQL

# load the dump (handle .sql or .sql.gz)
if [ -f /opt/smartemr/deploy/smartemr_db.sql.gz ]; then
  gunzip -c /opt/smartemr/deploy/smartemr_db.sql.gz | dc exec -T mysql mariadb -u root -p"${ROOT_PASS}" smartemr
else
  dc exec -T mysql mariadb -u root -p"${ROOT_PASS}" smartemr < /opt/smartemr/deploy/smartemr_db.sql
fi
# sanity: expect ~283 tables
dc exec -T mysql mariadb -u root -p"${ROOT_PASS}" -e "SELECT COUNT(*) AS tables FROM information_schema.tables WHERE table_schema='smartemr';"
```

## Step 6 — Restore the sites volume, then point `sqlconf.php` at `smartemr`

```bash
SITES_VOL=development-easy_sitesvolume
docker volume create "$SITES_VOL" >/dev/null
docker run --rm -v "$SITES_VOL":/sites -v /opt/smartemr/deploy:/backup:ro \
  alpine sh -c "rm -rf /sites/* && tar -xzf /backup/sites.tar.gz -C /sites"

# Overwrite the live sqlconf.php inside the volume with smartemr credentials.
docker run --rm -v "$SITES_VOL":/sites -e DB_PASS="$DB_PASS" alpine sh -c 'cat > /sites/default/sqlconf.php <<PHP
<?php
//  OpenEMR  MySQL Config
global \$disable_utf8_flag;
\$disable_utf8_flag = false;
\$host   = "mysql";
\$port   = "3306";
\$login  = "smartemr";
\$pass   = "'"$DB_PASS"'";
\$dbase  = "smartemr";
\$db_encoding = "utf8mb4";
\$sqlconf = [];
global \$sqlconf;
\$sqlconf["host"]= \$host;
\$sqlconf["port"] = \$port;
\$sqlconf["login"] = \$login;
\$sqlconf["pass"] = \$pass;
\$sqlconf["dbase"] = \$dbase;
\$sqlconf["db_encoding"] = \$db_encoding;
//////DO NOT TOUCH THIS///
\$config = 1; /////////////
PHP'
```

## Step 7 — Start OpenEMR

```bash
dc up -d openemr          # depends only on mysql; selenium/couchdb/openldap stay DOWN
# first boot runs composer/npm inside the container — can take 10–40 min
dc logs -f openemr        # watch until healthy; Ctrl-C when "apache2 ... started"
dc ps
curl -sI http://127.0.0.1:8300/ | head -5     # expect 302 -> interface/login/login.php
```

## Step 8 — CloudPanel front door

In CloudPanel:
1. **Add Site → Reverse Proxy** (or PHP site + custom vhost) for `smartemr.ipab.co.tz`,
   proxy pass to `http://127.0.0.1:8300`.
2. **SSL/TLS → Let's Encrypt** for the domain (issues + auto-renews the cert).
3. **Security → Basic Auth**: add a username/password (the temporary gate).

Reverse-proxy vhost must forward the real host + scheme so OpenEMR builds correct
HTTPS links and sets secure cookies. Ensure these headers are set:
```
proxy_set_header Host              $host;
proxy_set_header X-Real-IP         $remote_addr;
proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto https;
proxy_set_header X-Forwarded-Port  443;
```

## Step 9 — Verify

```bash
curl -skI https://smartemr.ipab.co.tz/ | head -8     # 401 until Basic Auth creds given, then 302
```
Browser: open `https://smartemr.ipab.co.tz/`, pass the Basic Auth prompt, then log in to
OpenEMR as `admin` (password = source `SECRETS.txt` line [3]). Walk: login page shows the
smartEMR branding + English/Swahili selector; log in; confirm Reception → Cashier →
Nurse → Doctor → Lab → Pharmacy menus per role (role users = source `SECRETS.txt` lines [4]–[9]).

## Troubleshooting

- **OpenEMR shows the setup/installer** → `sqlconf.php` has `$config = 0` or wrong creds.
  Re-do Step 6 (must be `$config = 1`, login/pass/dbase = `smartemr`).
- **"Authentication failed" connecting to DB** → `MYSQL_USER_PASS` in `.env` ≠ `$pass` in
  `sqlconf.php`, or the `smartemr` user/grants weren't created (Step 5).
- **Login loops / mixed-content / insecure-cookie warnings** → the reverse proxy isn't
  sending `X-Forwarded-Proto https`; fix the CloudPanel vhost headers (Step 8) and confirm
  `site_addr_oath=https://smartemr.ipab.co.tz` (Step 4).
- **Port 8300 reachable from the public internet** → the overlay binds `127.0.0.1` only;
  verify with `ss -tlnp | grep 8300` (should show `127.0.0.1:8300`, not `0.0.0.0`).

## Security note (temporary pilot)

This is the **dev-easy** stack and the project's `/security-review` is still outstanding.
Basic Auth over TLS is a reasonable *temporary* shield, but before any non-pilot or
sustained exposure: run `/security-review`, confirm no dev service ports are published,
and confirm the firewall + `127.0.0.1` bindings.
