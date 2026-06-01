# OpenEMR — Tanzanian OPD pilot

A pilot OpenEMR 8.0.0 deployment configured for the Reception → Cashier → Doctor → Lab → Pharmacy workflow of a Tanzanian outpatient clinic. Runs on Docker (OpenEMR's bundled `development-easy` compose) on macOS. **Localhost only — not production-hardened.**

The upstream OpenEMR project README is preserved at `OPENEMR_README.md`.

## URLs

| Service | URL | Credentials |
|---|---|---|
| OpenEMR (HTTP) | <http://localhost:8300> | `admin` / line **[3]** of `SECRETS.txt` |
| OpenEMR (HTTPS, self-signed) | <https://localhost:9300> | same |
| phpMyAdmin | <http://localhost:8310> | `root` / line **[1]** of `SECRETS.txt` |
| Mailpit (captured outgoing mail) | <http://localhost:8025> | — |
| Selenium VNC (test viewer) | `vnc://localhost:7900` | `openemr123` |

## Stack control

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/openemr/docker/development-easy

docker compose up -d        # start
docker compose ps           # status
docker compose logs -f      # tail logs
docker compose down         # stop (data persists in volumes)
docker compose down -v      # ⚠ stop AND WIPE all volumes (deletes DB!)
```

First boot takes **30–45 minutes** because the `dev-easy` image installs Chromium + composer deps + npm + the OpenEMR setup on first start. Subsequent `up -d` runs come up in seconds.

## Users (Phase 6 seed)

Each created with a strong randomized password — passwords are at lines **[4]–[9]** of `SECRETS.txt`.

| Username | ACL group | Provider? | Purpose |
|---|---|---|---|
| `admin` | Administrators | yes | System administration |
| `reception1` | Front Office | no | Patient registration, vitals |
| `cashier1` | Accounting | no | Payment confirmation |
| `doctor1` | Physicians | **yes** | Clinical notes, prescriptions |
| `lab1` | Clinicians | no | Lab result entry |
| `pharm1` | Clinicians | no | Pharmacy dispensing |
| `nurse1` | Clinicians | no | Triage / nursing |

Tell clinic staff to **change passwords on first login** (Top right → user icon → Edit profile).

## What's pre-configured

- **Locale**: `Africa/Dar_es_Salaam`, TZS (TSh), `DD/MM/YYYY`, 24-hour, country code `+255`.
- **Default Facility**: `Clinic — OPD`, Dar es Salaam, `+255 000 000 000`. Edit at Admin → Practice Settings → Facilities.
- **Service / consultation codes (HCPCS)** at standard price level: `REG` (registration), per-clinic consultations `CONS-GP`, `CONS-GYN`, `CONS-PAED`, `CONS-RCH`, `CONS-DENT` (also legacy `CONS-SPEC`, `CONS-FOLLOW`), plus `LAB-*` and `PROC-*`.
- **Clinics** (= appointment categories, one Health Facility): General OPD (GP), Gynaecology, Paediatrics, RCH/Antenatal, Dental — each with its own consultation fee. Edit/extend at Admin → Calendar → Categories and `scripts/seed-clinics.sql`.
- **Workflow appointment statuses**: `RG` Registered (Awaiting Cashier), `PD` Paid (Awaiting Triage), `TR` Triaged (Awaiting Doctor), `WD` With Doctor, `WL` Awaiting Lab, `LB` Lab Complete, `WP` Awaiting Pharmacy, `CM` Completed/Discharged.
- **Payment methods**: Cash, M-Pesa, Tigo Pesa, Airtel Money, Halopesa, Bank Transfer, NHIF, Other Insurance.
- **OPD Visit Note** Layout-Based Form (`LBFopd_visit`): 5 groups, 11 fields (Chief Complaints; HPI, Past Medical Hx, Family/Social Hx; Pre-exam Summary, General Exam, Systemic Exam; **Provisional Diagnosis** ICD-10, Investigations; **Final Diagnosis** ICD-10, Plan & Notes). Registered as a Clinical encounter form.

## OPD workflow (role-based)

Custom module **`oe-module-opd`** (`interface/modules/custom_modules/oe-module-opd/`) drives the OPD flow. Each role logs in to a **lean, role-specific menu** showing only its station:

| Role | Login | Lands on | Does |
|---|---|---|---|
| Reception | `reception1` | **Reception → Start OPD Visit** | Find patient, pick clinic, start visit → bills Registration (first visit) + clinic consultation, patient → `RG` |
| Cashier | `cashier1` | **Cashier → Worklist** | Collect payment (method + amount); when balance = 0 the visit auto-advances to `PD` and becomes visible to the nurse |
| Nurse | `nurse1` | **Triage → Queue** | Sees only **paid** patients; records Vitals + triage notes; routes to a doctor (`TR`) |
| Doctor | `doctor1` | **Clinics → My Clinic Queues** | Per-clinic queue (only clinics they are granted); opens the encounter, writes the OPD note, orders labs |
| Lab | `lab1` | **Laboratory** | Flow Board (Awaiting Lab) + enter/sign results |
| Pharmacy | `pharm1` | **Pharmacy** | Flow Board (Awaiting Pharmacy) + dispense from inventory |

- **Payment gate**: unpaid patients never reach the nurse (queues filter on the encounter balance). Encounters are **auto-created** when the visit starts — clinicians never create encounters, they only write into the open one.
- **Encounter view** shows the whole visit: HPI, history, PMH, exam, provisional + final diagnosis, investigations (Procedure Orders/results) and **medications dispensed**.
- **Doctor ↔ clinic grants** live in `opd_provider_clinic` (seed `scripts/seed-opd-config.sql`); add a row per doctor per clinic they serve.
- **Reproduce after a DB / volume reset** (in order): `scripts/seed-clinics.sql`, install the module `php scripts/install-opd-module.php`, `scripts/seed-opd-config.sql`, `scripts/seed-lbf-opd-visit.sql`, `scripts/seed-role-menus.sql`, `scripts/seed-feature-globals.sql`, and `./scripts/install-role-menus.sh` (menus live in the sites volume).
- **Pharmacy dispensary**: enabled, with **596 drug templates** seeded from `medication_list.csv` (cleaned via `scripts/clean_medication_csv.py`; ~42 rows flagged for clinic review in `medication_cleanup_audit.txt`).

## What you still need to do

| # | What | Where |
|---|---|---|
| 1 | Import the **ICD-10 codes** so the Diagnosis field can search | Admin → Other → External Data Loads → External ICD Import → ICD10, latest year |
| 2 | Add **initial Purchase transactions** to each drug template (lot, expiry, opening qty, unit cost) | Inventory → Drugs → click drug → Transactions → Purchase |
| 3 | **Review the 42 flagged drugs** in `medication_cleanup_audit.txt` — they were imported with `import=false` and won't appear in prescriptions until fixed | Inventory → Drugs (filter inactive) |
| 4 | Configure **lab tests** with reference ranges (e.g., Hb M 13–17 g/dL, F 12–15 g/dL) | Admin → Procedures → Configure |
| 5 | Replace the **placeholder facility name** with the real clinic name | Admin → Practice Settings → Facilities |
| 6 | **Smoke test** the end-to-end flow with the TEST patient | See `CLAUDE_CODE_INSTRUCTIONS.md` Phase 12 |

## Backups

A consistent dump of the DB + the documents volume runs nightly at 23:30 once the schedule is installed:

```bash
./scripts/install-backup-schedule.sh
```

Backups land in `~/openemr-backups/<YYYY-MM-DD_HHMM>/` and rotate after 14 days. **Each backup is ~17 MB**; sync `~/openemr-backups/` to external storage at least weekly.

Manual backup any time:

```bash
./scripts/backup.sh
```

### Restore test (recommended monthly)

```bash
ROOT_PW=$(awk '/MariaDB root/ {print $NF}' SECRETS.txt)
cd docker/development-easy

docker compose exec mysql mariadb -u root -p"$ROOT_PW" \
  -e "CREATE DATABASE openemr_restore_test CHARACTER SET utf8mb4;"

docker compose exec -T mysql mariadb -u root -p"$ROOT_PW" \
  openemr_restore_test < ~/openemr-backups/<latest>/openemr_db.sql

docker compose exec mysql mariadb -u root -p"$ROOT_PW" \
  -e "SELECT COUNT(*) FROM openemr_restore_test.users;"

docker compose exec mysql mariadb -u root -p"$ROOT_PW" \
  -e "DROP DATABASE openemr_restore_test;"
```

## Known limitations / not-done

- **Self-signed HTTPS** — fine for localhost; provision a real cert before exposing on a LAN.
- **Dev-easy compose, not production** — XDebug-aware image, Selenium/mailpit included. Migrate to `docker/production/` before non-pilot use.
- **No SMS reminders** — needs an external gateway (Africa's Talking, Twilio).
- **No NHIF claim export** — claims exported manually for now.
- **No Swahili UI translation** in OpenEMR 8.0.0 stable — labels can be renamed via Admin → List Editor.
- **Patient Portal disabled** — turn on under Admin → Config → Features when ready.
- **The OPD Visit Note LBF** is editable only by admins (Admin → Forms → Layouts). Clinical staff fill it but cannot change its structure.
- **Role exclusivity is menu-enforced for the clinical roles.** Cashier (money) and Pharmacy (dispensing) are locked at the ACL/URL layer; nurse/doctor/lab/pharm share the clinical permissions (`encounters/notes`, `patients/lab`) so their station screens are hidden by the menus but remain URL-reachable within the clinical group. Tightening needs per-role ACL splits — fine for a trusted localhost pilot; revisit before LAN exposure. Run `/security-review` after `git init`.

## Files in this directory

```
CLAUDE.md                     Always-loaded agent brief (platform, conventions)
CLAUDE_CODE_INSTRUCTIONS.md   Full phased playbook
OPENEMR_DEV_GUIDE.md          Upstream OpenEMR dev-guide (preserved from tarball)
OPENEMR_README.md             Upstream OpenEMR README (preserved from tarball)
PROGRESS.md                   Phase-by-phase setup log
README.md                     This file
SECRETS.txt                   All passwords (chmod 600, gitignored)

docker/development-easy/
  docker-compose.yml          Upstream — unmodified
  docker-compose.override.yml Tanzanian overlay (locale + strong creds)
  .env                        Sources the strong creds (gitignored)

medication_list.csv           Source 638-row drug list (untouched)
medication_list.cleaned.csv   Phase 9.0 output — schema with category + form split
medication_cleanup_audit.txt  Rows that need clinic admin review

scripts/
  backup.sh                   Nightly backup (DB + sites volume)
  build_drug_seed_sql.py      Generates seed-drugs.sql from cleaned CSV
  clean_medication_csv.py     Phase 9.0 cleanup
  com.openemr.backup.plist    launchd schedule (23:30 daily)
  install-backup-schedule.sh  Installs the plist
  seed-drugs.sql              596 drug-template inserts (regen via build_drug_seed_sql.py)
  seed-lbf-opd-visit.sql      OPD Visit Note LBF schema
  seed-phase-5and8.sql        Facility / 24h / apptstat / payment methods
  seed-service-codes.sql      OPD service codes (HCPCS)
  seed-service-prices.sql     Service code prices at 'standard' level
  seed-test-patient.sql       TEST Mwajuma Hassan (MWA001, pid=2)
  seed-users.sql              6 role-based users + ACL group wiring
```

All `seed-*.sql` files are idempotent.

## Resetting from scratch

```bash
cd docker/development-easy
docker compose down -v          # ⚠ wipes all volumes (DB + uploads)
docker compose up -d            # fresh re-init (30–45 min on first boot)
```

Then re-run the seed scripts in this order (see `PROGRESS.md` for context):
`seed-service-codes.sql`, `seed-service-prices.sql`, `seed-phase-5and8.sql`, `seed-users.sql`, `seed-drugs.sql`, `seed-lbf-opd-visit.sql`. Optional: `seed-test-patient.sql`.
