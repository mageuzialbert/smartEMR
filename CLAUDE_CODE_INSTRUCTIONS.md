# OpenEMR Installation & OPD Setup for Tanzanian Clinic — Claude Code Instructions (Docker stack)

> Audience: Claude Code agents working in `/Applications/XAMPP/xamppfiles/htdocs/openemr/` on macOS.
> Quick brief lives in `CLAUDE.md`. This doc is the **full playbook**. Resume state lives in `PROGRESS.md`.
>
> **Stack pivot 2026-05-29:** the install runs inside Docker containers (OpenEMR's bundled `docker/development-easy/` compose). The earlier XAMPP-era phases (manual php.ini patch, manual DB create, web installer walkthrough) are now mostly automated by the container — see Phase 1–4 below.

## Context

**Project root (host):** `/Applications/XAMPP/xamppfiles/htdocs/openemr/`
**Compose dir:** `docker/development-easy/` — start commands run from here (or use `-f` flag).
**Container webroot (inside openemr container):** `/var/www/localhost/htdocs/openemr` — bind-mounted from project root.
**OpenEMR URL:** `http://localhost:8300` (HTTP) or `https://localhost:9300` (HTTPS, self-signed).

**Platform — verified, do not re-detect:**
- macOS (Darwin), zsh.
- Docker Desktop installed; `/Applications/XAMPP/xamppfiles/htdocs/openemr` granted in Settings → Resources → File Sharing.
- Container image: `openemr/openemr:flex` ships PHP 8.2 with `intl`, `imagick`, `sodium` and every other required extension.
- MariaDB 11.8 inside the `mysql` container; root password is line [1] of `SECRETS.txt`.
- XAMPP at `/Applications/XAMPP/xamppfiles/` keeps running for the sibling `ipab-pos` project; do not touch.

**Hard constraints (also in `CLAUDE.md` — repeated here for completeness):**
- Never edit `docker-compose.override.yml` to remove the strong-credential overrides.
- Pause and confirm before any destructive action (`docker compose down -v`, `docker volume rm`, dropping DBs from within containers).
- Tanzanian defaults: `Africa/Dar_es_Salaam`, TZS, `+255`, `DD/MM/YYYY`, 24h, English (primary).
- Medication seed data → cleaned in Phase 9.0 → `medication_list.cleaned.csv`.
- Never paste real passwords into chat output. Reference `SECRETS.txt` by line number. (`docker compose config` resolves them inline — avoid sharing that output.)

---

## Phase 0 — Agent infrastructure  (done)

Already complete. Files in place: `CLAUDE.md`, `.claude/settings.local.json`, `.gitignore`, this playbook.

---

## Phase 1 — Pre-flight checks  (mostly moot under Docker)

The XAMPP-era checks (`php.ini`, host PHP extensions) no longer apply — the openemr container brings its own. The only host-level checks that matter:

```bash
docker --version && docker compose version       # Docker Desktop is running
df -h /                                          # ≥ 10 GB free for images + DB
```

The XAMPP-era `php.ini` patches (timezone, post_max_size) were applied earlier — harmless, leave them. The `scripts/patch-php-ini.sh` is historical and can be removed once Phase 13 is reached.

---

## Phase 2 — Bring up the Docker stack

### 2.1 The override

`docker/development-easy/docker-compose.override.yml` is auto-loaded alongside the upstream compose. It:
- Overrides the insecure default passwords (`admin/pass`, `root/root`, `openemr/openemr`) with values from `docker/development-easy/.env` (chmod 600, gitignored, sourced from `SECRETS.txt`).
- Pre-sets Tanzanian globals: timezone, currency, phone country code, date/time format.
- Turns XDebug off.

### 2.2 Start

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/openemr/docker/development-easy
docker compose up -d
docker compose ps                                # all 7 services Up
```

### 2.3 Wait for first-boot init (~3–10 min)

The openemr container runs composer install, npm install, OpenEMR's auto-installer, and applies all `OPENEMR_SETTING_*` env vars on first start. Watch:

```bash
docker compose ps openemr                        # STATUS: Up X (health: starting) → Up X (healthy)
docker compose logs -f openemr                   # follow init
```

### 2.4 Verify

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8300        # expect 200 or 302
docker compose exec mysql mariadb -u openemr -p<line[2]> -e "SHOW DATABASES;" openemr
```

Then in a browser: `http://localhost:8300` → login `admin` / line [3] of `SECRETS.txt`.

---

## Phase 3 — Database (auto-provisioned)

No manual `CREATE DATABASE` or `CREATE USER` needed — the openemr container does it on first boot using the env vars from the override. Credentials:

| User | Source |
|---|---|
| `root` | line [1] of `SECRETS.txt` (set via `MYSQL_ROOT_PASSWORD` in override) |
| `openemr` | line [2] of `SECRETS.txt` (set via `MYSQL_PASS` in override) |

**Connect from host:**
```bash
docker compose exec mysql mariadb -u root -p<line[1]>                 # CLI inside container
mariadb -h 127.0.0.1 -P 8320 -u openemr -p<line[2]> openemr           # CLI from host (mariadb-client)
open http://localhost:8310                                            # phpMyAdmin (root / line[1])
```

---

## Phase 4 — OpenEMR setup (auto-completed by the container)

The dev-easy image runs OpenEMR's setup on first boot. By the time the healthcheck passes, the admin user exists and the DB is populated.

**Verify:**
1. `http://localhost:8300` redirects to login.
2. Log in as `admin` / line [3] of `SECRETS.txt`.
3. Admin → Config → **Locale** tab: timezone = `Africa/Dar_es_Salaam`, date format = `DD/MM/YYYY`, phone country code = `255` (all pre-set by override).

If any value is wrong, set it manually here. The override values are applied at install time only — they do not enforce on subsequent boots.

---

## Phase 5 — Initial localisation for Tanzania

Most of these are pre-applied by the override. Walk the screens to confirm, then add the items that env vars can't set.

### 5.1 Globals (Admin → Config) — confirm

**Locale tab** — confirm: `Africa/Dar_es_Salaam`, TZS (TSh), `DD/MM/YYYY`, 24h, `+255`.
**Appearance** — Theme `manila` or `light`; Application title `Clinic — OPD System` (placeholder).
**Features** — Patient portal = No; Inventory = Yes; Immunizations = Yes; Lab integration = Yes; Fee Sheet = Yes.

Save at the bottom of each tab.

### 5.2 Facility

Admin → Practice Settings → **Facilities** → edit Default Facility: name, address, phone (+255 …), mark billing & service location.

### 5.3 Service codes

Admin → Patient Administration → **Services / Codes**. Add or enable:

| Code | Description | Default Price (TZS) |
|---|---|---|
| `CONS-GP` | General Consultation | 10,000 |
| `CONS-SPEC` | Specialist Consultation | 30,000 |
| `CONS-FOLLOW` | Follow-up Visit | 5,000 |
| `LAB-MRDT` | Malaria Rapid Diagnostic Test | 3,000 |
| `LAB-CBC` | Complete Blood Count | 8,000 |
| `LAB-UA` | Urinalysis | 4,000 |
| `LAB-STOOL` | Stool Microscopy | 4,000 |
| `LAB-BS` | Blood Smear | 3,000 |
| `LAB-PT` | Pregnancy Test | 5,000 |
| `LAB-RBS` | Random Blood Sugar | 3,000 |
| `LAB-HIV` | HIV Rapid Test | 0 (free per NACP) |
| `PROC-INJ` | Injection Administration | 2,000 |
| `PROC-DRESS` | Wound Dressing | 5,000 |

---

## Phase 6 — Role-based users

Admin → Users → **Add User**.

### 6.1 ACL groups
- Administrators, Physicians (existing)
- Clinicians, Front Office, Accounting, Emergency Login (confirm or create)

### 6.2 Users (test pilot)

| Username | Role | Group | Purpose |
|---|---|---|---|
| `reception1` | Front Office | Front Office | Registration |
| `cashier1` | Accounting | Accounting | Payment confirmation |
| `doctor1` | Physician | Physicians | Clinical notes |
| `lab1` | Clinician | Clinicians | Lab results |
| `pharm1` | Clinician | Clinicians | Pharmacy dispensing |
| `nurse1` | Clinician | Clinicians | Triage |

For each: strong password (`openssl rand -base64 12`), append to `SECRETS.txt` "Per-role users" section; default facility = main; provider checkbox ON only for `doctor1`; `doctor1` specialty = `General Practitioner`.

---

## Phase 7 — Custom OPD encounter form

### 7.1 Layout-Based Form

Admin → Forms → Layouts → **Add New Layout**:
- Layout ID `LBFopd_visit`
- Title `OPD Visit Note`
- Mapping `Clinical`
- Notes `Standard outpatient visit clinical note for Tanzanian OPD`

### 7.2 Fields

| Order | Group | Field ID | Label | Data Type | Required | Notes |
|---|---|---|---|---|---|---|
| 1 | 1Chief Complaint | `chief_complaints` | Chief Complaints | Text List (multi-entry) | YES | Multiple rows |
| 2 | 2History | `hpi` | History of Presenting Illness | Textarea | YES | 8 rows |
| 3 | 2History | `past_medical_hx` | Past Medical History | Textarea | No | 4 rows |
| 4 | 2History | `family_social_hx` | Family & Social History | Textarea | No | 4 rows |
| 5 | 3Examination | `summary_one` | Summary (Pre-examination) | Textarea | No | 3 rows |
| 6 | 3Examination | `general_exam` | General Examination | Textarea | YES | 6 rows |
| 7 | 3Examination | `systemic_exam` | Systemic Examination | Textarea | No | 8 rows |
| 8 | 4Diagnosis | `diagnosis` | Diagnosis (ICD-10) | Diagnosis (ICD10) lookup | YES | Multi-select |
| 9 | 4Diagnosis | `clinical_notes` | Additional Notes | Textarea | No | 3 rows |

Group prefixes `1`/`2`/`3`/`4` force ordering.

### 7.3 Validation
For required: Edit Options → **R (Required)** + Description tooltip. Allow multi for `chief_complaints`.

### 7.4 ICD-10
Admin → Practice Settings → **Code Types** → confirm `ICD10` enabled.
If Diagnosis lookup returns nothing: Admin → Other → **External Data Loads** → External ICD Import → ICD10, latest year → Import.

### 7.5 Attach
Admin → Forms → **Registered** → `LBFopd_visit` → State = Active, check Encounter Menu.

---

## Phase 8 — OPD workflow scaffolding

### 8.1 Statuses (Admin → Lists → `apptstat`)

| Code | Name | Color |
|---|---|---|
| `RG` | Registered (Awaiting Cashier) | Yellow |
| `PD` | Paid (Awaiting Doctor) | Light Green |
| `WD` | With Doctor | Blue |
| `WL` | Awaiting Lab | Orange |
| `LB` | Lab Complete | Purple |
| `WP` | Awaiting Pharmacy | Pink |
| `CM` | Completed | Green |

### 8.2 Reception workflow (`reception1`)
1. Patient → New/Search. Required for TZ: full name, DOB, sex, phone (+255), NIDA (optional), next of kin, ward/street, region.
2. Create appointment, status `RG`, assign provider.
3. Vitals (built-in form).
4. ACL: `patients > demo (write)`, `encounters > coding (read)`. No prescription/lab write.

### 8.3 Cashier workflow (`cashier1`)
1. Calendar → `RG`.
2. Open patient → encounter → Fee Sheet → add `CONS-GP` etc.
3. Save → Checkout → Payment.
4. Status `PD`.

**Payment methods:** Admin → Lists → `payment_method` → add `Cash`, `M-Pesa`, `Tigo Pesa`, `Airtel Money`, `Halopesa`, `Bank Transfer`, `Insurance — NHIF`, `Insurance — Other`.

### 8.4 Doctor workflow (`doctor1`)
1. Calendar → `PD`. Status → `WD`.
2. Add OPD Visit Note (Phase 7).
3. Procedures → Order → select tests → `WL`.
4. Prescriptions → Add → drug from dispensary → tick Send to Pharmacy → `WP`.
5. Sign and close.

### 8.5 Lab workflow (`lab1`)
1. Procedures → Pending Reviews.
2. Open order → results → Result Status = Final.
3. Status `LB`.
4. **One-time:** Admin → Procedures → Configure → procedure groups + tests matching Phase 5.3 codes. Reference ranges per TZ norms.

### 8.6 Pharmacy workflow (`pharm1`)
1. Procedures → Dispense Pending or open encounter.
2. **Save and Dispense** (deducts stock). Status `CM`.

---

## Phase 9.0 — Medication CSV cleanup

Source `medication_list.csv` has issues:
- Malformed final row (HTML garbage from a web export)
- ~50 rows missing Generic Name
- ` -- Form` suffix mixed into Category column
- Prices like `TSh 1,234.50` need numeric extraction

Produce `medication_list.cleaned.csv` with schema:
```
brand,generic,unit_purchase_tzs,selling_tzs,category,form,import,notes
```

Rules:
- Drop the HTML garbage row.
- Split `Category` on ` -- ` into `category` + `form` when right side matches a known form (Tablet, Capsule, Syrup, Suspension, Injection, Cream, Gel, Drops, Suppository, Spray, IV).
- Blank `Generic Name` → keep, set `import=false`, log to `medication_cleanup_audit.txt`.
- Parse prices to numeric.

Use awk or a short Python one-off.

---

## Phase 9 — In-house pharmacy

### 9.1 Enable

Admin → Config → Features → **Pharmacy Dispensing** = ON. Save and re-login.

### 9.2 Drug templates

For each `import=true` row in `medication_list.cleaned.csv`:

Inventory → Drugs → **Add Drug**:
- Name (generic preferred)
- Form (from cleaned `form` column)
- Size & Unit (parse from name, e.g., `500` `mg`)
- **Tick "Template"**
- Active = Yes; Consumable = No (Yes for syringes)

Bulk insert via API or direct `drugs` table seed is acceptable — pause and confirm approach before bulk-loading.

### 9.3 Initial stock

For each template, drug → Transactions → **Purchase**: lot, manufacturer, expiry, quantity, unit cost (TZS), vendor, warehouse `Main`.

### 9.4 Verify auto-deduction

Dummy patient → encounter → prescribe Paracetamol 500mg, qty 20 → **Save and Dispense** → confirm On Hand dropped by 20.

---

## Phase 10 — Reports

Reports menu — confirm each renders without PHP error:
- Patient List · Appointments · Encounters · Cash Receipts · Receipts Summary · Inventory On Hand · Inventory Transactions · Patient List Creation · Pending Procedure Order Results

Tail logs while testing:
```bash
docker compose logs -f openemr
```

Bookmark each report in browser.

---

## Phase 11 — Backups

Patient data lives in:
- MariaDB `openemr` DB inside the `mysql` container's `databasevolume`
- `sitesvolume` (sites/default/documents/ — uploads, scans, generated PDFs)

### 11.1 Backup script

`scripts/backup.sh`:

```bash
#!/bin/bash
# OpenEMR daily backup (Docker dev-easy stack)
set -euo pipefail

COMPOSE_DIR=/Applications/XAMPP/xamppfiles/htdocs/openemr/docker/development-easy
DB_USER=openemr
DB_PASS_LINE_2=$(awk '/MariaDB openemr-user password:/ {print $NF}' \
  /Applications/XAMPP/xamppfiles/htdocs/openemr/SECRETS.txt)
BACKUP_ROOT="$HOME/openemr-backups"
TIMESTAMP=$(date +%Y-%m-%d_%H%M)
DEST="$BACKUP_ROOT/$TIMESTAMP"
mkdir -p "$DEST"

# 1. DB dump via mysqldump in the mysql container (single-transaction = consistent snapshot)
cd "$COMPOSE_DIR"
docker compose exec -T mysql mariadb-dump \
  -u "$DB_USER" -p"$DB_PASS_LINE_2" \
  --single-transaction --quick --add-drop-table \
  openemr > "$DEST/openemr_db.sql"

# 2. Sites volume (documents, generated PDFs, smarty templates) — tar from inside a throwaway container
docker run --rm \
  -v development-easy_sitesvolume:/sites:ro \
  -v "$DEST":/backup \
  alpine tar -czf /backup/sites.tar.gz -C /sites .

# 3. Rotate: keep last 14 days
find "$BACKUP_ROOT" -maxdepth 1 -type d -mtime +14 -exec rm -rf {} \;

echo "Backup complete: $DEST"
```

```bash
chmod +x scripts/backup.sh
```

### 11.2 Schedule

`launchd` plist `~/Library/LaunchAgents/com.openemr.backup.plist` (23:30 daily):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>com.openemr.backup</string>
  <key>ProgramArguments</key><array>
    <string>/bin/bash</string>
    <string>/Applications/XAMPP/xamppfiles/htdocs/openemr/scripts/backup.sh</string>
  </array>
  <key>StartCalendarInterval</key><dict>
    <key>Hour</key><integer>23</integer><key>Minute</key><integer>30</integer>
  </dict>
  <key>StandardOutPath</key><string>/tmp/openemr-backup.log</string>
  <key>StandardErrorPath</key><string>/tmp/openemr-backup.err</string>
</dict></plist>
```

Load: `launchctl load ~/Library/LaunchAgents/com.openemr.backup.plist`

**Off-machine copy:** sync `~/openemr-backups/` to external USB or cloud weekly.

### 11.3 Restore test

```bash
docker compose exec mysql mariadb -u root -p<line[1]> -e "CREATE DATABASE openemr_restore_test CHARACTER SET utf8mb4;"
docker compose exec -T mysql mariadb -u root -p<line[1]> openemr_restore_test < ~/openemr-backups/<latest>/openemr_db.sql
docker compose exec mysql mariadb -u root -p<line[1]> -e "SELECT COUNT(*) FROM openemr_restore_test.users;"
docker compose exec mysql mariadb -u root -p<line[1]> -e "DROP DATABASE openemr_restore_test;"
```

---

## Phase 12 — End-to-end smoke test

Use the `/verify` or `/run` skill to drive the browser. Selenium is already in the dev-easy stack — VNC viewer at `vnc://localhost:7900` (password `openemr123`) lets you watch.

Always use **TEST patient**, never real names.

1. `reception1` → register "TEST Mwajuma Hassan", DOB 1985-06-15, female, +255 712 000 000. Vitals: BP 130/85, T 37.2°C, HR 88. Status `RG`.
2. `cashier1` → find appointment → Fee Sheet add `CONS-GP` → record TSh 10,000 cash → status `PD`.
3. `doctor1` → open encounter → OPD Visit Note:
   - Chief: "Headache", "Fever"
   - HPI: "3-day history of fever, worse at night, frontal headache..."
   - General exam: "Alert, febrile, no pallor"
   - Diagnosis: `B54` (Unspecified malaria) → Save.
4. Order **LAB-MRDT** → `WL`.
5. Prescribe **Artemether-Lumefantrine 20/120mg, 4 tabs BD × 3d, dispense 24** → tick Send to Pharmacy → `WP`.
6. `lab1` → result "Positive for P. falciparum" → save.
7. `pharm1` → note ALu stock → Save and Dispense → confirm stock −24 → `CM`.
8. `admin` → Patient List Creation, diagnosis `B54` → TEST patient appears.

Eight passes → pilot ready.

---

## Phase 13 — Hand-off

Create `README.md`:
- Login URL `http://localhost:8300`
- Compose start/stop: `cd docker/development-easy && docker compose {up -d|down|ps|logs -f}`
- Users created (usernames only)
- Custom encounter form name
- Backup schedule & restore steps
- Phase 12 smoke test as recovery acceptance test
- Known gaps:
  - LBF OPD form not directly editable by clinicians — admin only
  - SMS reminders not configured (external gateway needed)
  - NHIF claim format not built-in (manual export)
  - No native Swahili UI in 8.0.0
  - **HTTPS uses self-signed cert** — fine for localhost; real cert needed before LAN/internet exposure
  - **Dev-easy compose is not production hardened** — migrate to `docker/production/` compose before exposing to anything but localhost

Run `/security-review`. Check:
- `SECRETS.txt` is chmod 600 and in `.gitignore`
- `docker/development-easy/.env` is chmod 600 and in `.gitignore`
- No DB creds in committed PHP
- `sites/default/documents/` is gitignored
- HTTPS self-signed warning documented for users

---

## Important rules

1. **Show the exact command** before any destructive Docker op (`down -v`, `volume rm`, `exec mysql DROP`, etc.).
2. **Never paste real passwords into chat** — reference `SECRETS.txt` by line number.
3. **On failure**, capture `docker compose logs --tail=200 <service>` and the healthcheck status — do not auto-retry blindly.
4. **Tanzanian defaults override** generic OpenEMR defaults.
5. **TEST patient** ("TEST Mwajuma Hassan") for any verification.
6. **End of each phase**: append a note to `PROGRESS.md`.

## Escalate to user when

- Any container goes `unhealthy` and stays there
- `docker compose up` reports `mounts denied` (File Sharing regression)
- OpenEMR auto-installer leaves a half-configured DB
- ICD-10 import fails repeatedly
- Dispensary auto-deduction broken after Phase 9.4
- Any error containing "fatal", "permission denied", "corrupt", "OOM"

Stop, dump logs, ask.

## Files this project will produce

- `SECRETS.txt`, `docker/development-easy/.env` — secrets (both gitignored, chmod 600)
- `PROGRESS.md`, `README.md`
- `medication_list.cleaned.csv` + `medication_cleanup_audit.txt`
- `scripts/backup.sh` + `~/Library/LaunchAgents/com.openemr.backup.plist`
- `scripts/smoke-test.md`
- (XAMPP-era leftovers: `scripts/patch-php-ini.sh`, `/Applications/XAMPP/xamppfiles/etc/php.ini.bak.20260529` — harmless, can be deleted at Phase 13)

**End of playbook. Begin at Phase 5 (Phase 0–4 are now automated).**
