# OpenEMR — Tanzanian OPD pilot (agent brief)

This file is always loaded into every agent session in this directory. Keep it short. The full phased playbook lives in `CLAUDE_CODE_INSTRUCTIONS.md`; current progress lives in `PROGRESS.md`.

## Project

Install and configure **OpenEMR 8.0.0** as an outpatient (OPD) system for a Tanzanian clinic. Reception → Cashier → Doctor → Lab → Pharmacy. Custom Layout-Based Form for the OPD encounter; in-house pharmacy dispensary tied to inventory; daily backups. Pilot, localhost only — not exposed to LAN/internet.

## Platform — Docker stack (pivoted 2026-05-29 from XAMPP)

The OpenEMR install runs **inside Docker containers**, not the host XAMPP. XAMPP is still installed and may be running for sibling projects, but OpenEMR is wholly containerised.

- **Compose file:** `docker/development-easy/docker-compose.yml` + project-local `docker/development-easy/docker-compose.override.yml` (Tanzanian locale + strong creds from `.env`).
- **Project root (host):** `/Applications/XAMPP/xamppfiles/htdocs/openemr/` — bind-mounted into the openemr container at `/var/www/localhost/htdocs/openemr` (RW) and `/openemr` (RO).
- **Container image:** `openemr/openemr:flex` — ships intl, sodium, imagick, all required PHP exts.
- **Run/stop:** `cd docker/development-easy && docker compose {up -d|down|ps|logs -f}`. Stack auto-restarts unless explicitly `down`ed.

### URLs

| Service | URL | Credentials |
|---|---|---|
| OpenEMR (HTTP) | `http://localhost:8300` | `admin` / line [3] of `SECRETS.txt` |
| OpenEMR (HTTPS, self-signed) | `https://localhost:9300` | same |
| phpMyAdmin | `http://localhost:8310` | `root` / line [1] of `SECRETS.txt` |
| Mailpit (captures outgoing mail) | `http://localhost:8025` | (none) |
| MariaDB (host-mapped) | `localhost:8320` | `openemr` / line [2] of `SECRETS.txt` |
| Selenium VNC (smoke-test viewing) | `vnc://localhost:7900` | password `openemr123` |

### Shell into running containers

```bash
# OpenEMR app shell
docker compose -f docker/development-easy/docker-compose.yml exec openemr bash

# MariaDB CLI
docker compose -f docker/development-easy/docker-compose.yml exec mysql mariadb -u root -p<line[1]> openemr
```

## Locale defaults (Tanzania) — pre-applied via override

The override sets these at container init. Verify in Admin → Config → Locale on first login.

- Timezone: `Africa/Dar_es_Salaam`
- Currency: TZS (`TSh`), 0 decimal places
- Phone country code: `+255`
- Date display: `DD/MM/YYYY`, time: 24h
- Primary UI language: English. Note Swahili labels where useful.
- Payment methods to register (Phase 8): Cash, M-Pesa, Tigo Pesa, Airtel Money, Halopesa, Bank Transfer, NHIF, Other Insurance.

## Hard rules

1. **Never** edit `docker-compose.override.yml` to remove the strong-credential overrides. The dev-easy defaults (admin/pass, root/root) are insecure and must not appear in any committed file.
2. **Never** paste real passwords into chat. Reference `SECRETS.txt` by line number. (Note: `docker compose config` will substitute them into resolved YAML — avoid sharing that output.)
3. **Pause for confirmation** before: `docker compose down -v` (wipes volumes = wipes the DB), `docker volume rm`, dropping DBs from inside containers, anything that could remove patient data.
4. **Stop and report** on errors containing "fatal", "permission denied", "corrupt", "unhealthy", or any 5xx response from the OpenEMR healthcheck. Capture `docker compose logs --tail=200 openemr` and ask.
5. Use **"TEST Mwajuma Hassan"** as the dummy patient for verification — never real names.
6. **At the end of each phase**, append a one-paragraph note to `PROGRESS.md`.
7. Gitignored (do not commit): `SECRETS.txt`, `docker/development-easy/.env`, `openemr-backups/`, the source tarball, `sites/default/documents/`, `.env*` (except `.env.example`).

## Default skills

- `/verify` — after any phase that changes UI/workflow (Phases 4–9, 12).
- `/run` — to launch a browser session against `http://localhost:8300`.
- `/security-review` — mandatory before Phase 13 sign-off. OpenEMR holds PHI; check Docker secrets (compose env), default passwords, exposed ports, `sites/` volume perms.
- `/init` — only on explicit user request.
- `xlsx`/`pdf` skills — NOT needed; medication CSV cleanup is plain CSV (awk/python).

## Subagent guidance

- `Explore` — read-only lookups in this dir, in the OpenEMR codebase (mounted), or in the running containers via `docker compose exec`.
- `Plan` — any structural change (LBF redesign, workflow rearrangement, switching from dev-easy to production compose).
- `general-purpose` — multi-step research (ICD-10 import, MariaDB schema introspection, OpenEMR Docker image internals).
- Pass each subagent the platform line ("Docker dev-easy stack, OpenEMR at http://localhost:8300, project root at /Applications/XAMPP/xamppfiles/htdocs/openemr/") so they don't re-detect.

## Key files in this dir

- `CLAUDE_CODE_INSTRUCTIONS.md` — full phased playbook (source of truth).
- `OPENEMR_DEV_GUIDE.md` — OpenEMR's own dev guide (project structure / tech stack), preserved from upstream tarball.
- `docker/development-easy/docker-compose.yml` — upstream compose (unmodified).
- `docker/development-easy/docker-compose.override.yml` — our Tanzanian + strong-creds overlay.
- `docker/development-easy/.env` — secrets sourced for the override (gitignored).
- `medication_list.csv` — raw seed, 638 rows, ` -- Form` suffix on categories, ~50 blank generic names, malformed final row. Do not import as-is.
- `medication_list.cleaned.csv` — Phase 9.0 output (does not exist yet).
- `PROGRESS.md` — phase log.
- `SECRETS.txt` — all passwords (gitignored, chmod 600).
- `scripts/patch-php-ini.sh` — historical, was XAMPP-era Phase 1; can be deleted later.
- `scripts/backup.sh` — daily backup (Phase 11; will be Docker-aware).
- `README.md` — hand-off doc (Phase 13).

## XAMPP — leave alone

XAMPP at `/Applications/XAMPP/xamppfiles/` keeps running for the sibling `ipab-pos` project. Ports 80/443/3306 belong to XAMPP. OpenEMR uses 8300/9300/8320 (no conflict). The XAMPP-era `php.ini` patches (Phase 1) and `php.ini.bak.20260529` are harmless; leave them.
