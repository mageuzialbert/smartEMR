# OpenEMR Setup — Progress Log

Append one short note per phase completed. Resume work by reading the last entry.

---

## Phase 0 — Agent infrastructure  ✅  2026-05-29

Created `CLAUDE.md`, `.claude/settings.local.json`, `.gitignore`. Rewrote `CLAUDE_CODE_INSTRUCTIONS.md` end-to-end for macOS (paths, zsh commands, `launchd`/`cron` schedule, `bash` backup script). Added Phase 0 and Phase 9.0 (CSV cleanup) to the playbook. Verified by a fresh subagent that reads only `CLAUDE.md` and correctly answers platform, locale, skill defaults, and php.ini rule.

## Phase 1 — Pre-flight checks  ✅  2026-05-29

- Services: httpd ✓, mysqld ✓
- PHP 8.2.4, MariaDB 10.4.28, 82 GB free
- Extensions present: openssl, pdo_mysql, mysqli, mbstring, xml, gd, curl, zip, ldap, soap, sockets, calendar, xsl
- **Extensions missing: `intl`, `sodium`** — not in extension_dir for this XAMPP build. User decision: proceed without them. Impact: FHIR / OAuth2 / external API tokens will not work; OPD pilot workflow unaffected. Recorded in `README.md` as known limitation when Phase 13 is reached.
- Patched `/Applications/XAMPP/xamppfiles/etc/php.ini` via `scripts/patch-php-ini.sh`:
  - `post_max_size`: 40M → **64M**
  - `upload_max_filesize`: 40M → **64M**
  - `date.timezone`: Europe/Berlin → **Africa/Dar_es_Salaam**
- Backup: `/Applications/XAMPP/xamppfiles/etc/php.ini.bak.20260529` (owner root)
- Apache reloaded; live `phpinfo` confirms all three values active.

## Phase 2 — Download + extract OpenEMR 8.0.0  ✅  2026-05-29

- Downloaded `v8_0_0.tar.gz` (93 MB, sha256 `0d080a94e66a9cdbd6d338089c59c05cd7bb6194bb545364dce2bbf94d03387f`).
- Extracted with `--strip-components=1` into webroot (9,404 entries).
- Collisions handled: OpenEMR's own `CLAUDE.md` saved as `OPENEMR_DEV_GUIDE.md` (159-line dev guide for OpenEMR codebase — useful future reference for agents). OpenEMR's `.gitignore` saved as `.gitignore.openemr`; merged its framework patterns (vendor, node_modules, etc.) into our `.gitignore`.
- `medication_list.csv` and `scripts/patch-php-ini.sh` survived (not in tarball).
- `setup.php` reachable HTTP-wise but returns **500**.
- Root cause: tarball doesn't ship `vendor/`. `composer install` required first. **But composer.json requires PHP extensions `intl`, `imagick`, `sodium` — none present in this XAMPP build.** `composer install --ignore-platform-reqs` would proceed but lets runtime fatals into the OPD workflow (PDF generation, prescription printing, FHIR/OAuth, locale-aware date formatting).

### PIVOT 2026-05-29 — Docker stack

User decision: drop the XAMPP-based install entirely and use OpenEMR's bundled Docker compose (`docker/development-easy/docker-compose.yml`). Containers ship with all required PHP extensions. XAMPP can stay running (dev-easy uses ports 8300/9300/8310/8320 — no conflicts).

**Implications for the playbook:**
- Phase 1 (XAMPP `php.ini` patches): **moot** for OpenEMR — but harmless, XAMPP keeps running for other projects (e.g., `ipab-pos`). Backup `php.ini.bak.20260529` left in place.
- Phase 3 (manual DB create) and Phase 4 (web installer walkthrough): **automated** by the compose file. OpenEMR auto-initialises DB; admin user is `admin/pass` (we override via `docker-compose.override.yml` with strong creds saved to `SECRETS.txt`).
- Phase 11 backup: targets named Docker volumes (`databasevolume`, `sitesvolume`) via `docker compose exec` `mysqldump` and `docker run --rm` tar — not local `mysqldump`.
- Phases 5–10, 12, 13: same goals, accessed via `http://localhost:8300` (or HTTPS 9300).

`CLAUDE.md` and `CLAUDE_CODE_INSTRUCTIONS.md` will be updated to reflect the Docker stack once `docker compose up` is confirmed working.

## Phase 2.3–2.5 (Docker)  ✅  2026-05-29

- Docker Desktop installed (Docker 29.5.2 / Compose v5.1.3).
- Generated strong passwords with `openssl rand`, wrote `SECRETS.txt` (chmod 600) and `docker/development-easy/.env` (chmod 600). Both gitignored.
- Wrote `docker/development-easy/docker-compose.override.yml`: strong creds + `OPENEMR_SETTING_*` env vars for Tanzania (timezone, currency, country code, date/time format), XDebug off.
- First `docker compose up` hit two issues: (a) one image pull stalled with `unexpected EOF` (resolved by killing + retry), then (b) `mounts denied` because Docker Desktop's File Sharing didn't include `/Applications/...`. User added `/Applications/XAMPP/xamppfiles/htdocs/openemr` via Settings → Resources → File Sharing.
- Stack came up cleanly on the third attempt. Init was long (~44 min) because `dev-easy` first-boot does `apk add chromium`, `composer install`, `npm install`, and OpenEMR auto-setup — much heavier than the compose's 3-min `start_period` healthcheck assumes.
- All 7 containers healthy. HTTP 302 at `localhost:8300`, HTTPS 302 at `localhost:9300`. 283 tables in `openemr` DB. Tanzania globals confirmed via `SELECT gl_name, gl_value FROM globals` — all 5 settings applied at install time.

## Phase 2.6 — Pivot docs to Docker stack  ✅  2026-05-29

`CLAUDE.md` rewritten for Docker (URLs, container shell commands, override-based credential flow). `CLAUDE_CODE_INSTRUCTIONS.md` end-to-end rewrite: Phase 1 marked moot (host PHP irrelevant), Phase 2 = bring up stack, Phases 3–4 = automated, backup script in Phase 11 reworked to use `docker compose exec mysqldump` + alpine-tar of `sitesvolume`.

## Phase 3 (Docker DB) + Phase 4 (web installer)  ✅  automated 2026-05-29

Both phases were handled automatically by the openemr container's first-boot init using the env vars from the override. Admin user `admin` (password = line [3] of `SECRETS.txt`) auto-created. `openemr` DB user (password = line [2]) auto-provisioned. DB schema loaded (283 tables). No manual installer walkthrough required.

## Phase 5 — Tanzanian localisation  ⏳  partially auto-applied

The five locale globals are pre-set at install time via override env vars (verified in DB). Remaining work for this phase:
- Walk Admin → Config tabs and confirm UI shows the values
- Set Default Facility (name, address, phone)
- Add the service codes (CONS-GP, LAB-MRDT, etc.) from the playbook table

## Phase 5.3 — Service codes  ✅  2026-05-29

Seeded 13 OPD codes (CONS-GP/SPEC/FOLLOW; LAB-MRDT/CBC/UA/STOOL/BS/PT/RBS/HIV; PROC-INJ/DRESS) into `codes` with `code_type=3` (HCPCS) and matching rows in `prices` at `pr_level='standard'`. Two SQL files in `scripts/`: `seed-service-codes.sql`, `seed-service-prices.sql` (both idempotent). Verified in Fee Sheet UI — HCPCS search returns the codes; price flows from `prices` table.

## Test patient — Mwajuma TEST Hassan (MWA001, pid=2)  ✅  2026-05-29

Seeded directly via `scripts/seed-test-patient.sql` to unblock Fee Sheet testing. Encounter created via UI (encounter id=5 — dev-easy bootstrap pre-created some).

## Phase 6 — Role-based users  ✅  2026-05-29

Seeded 6 users via `scripts/seed-users.sql` (idempotent, deletes by username first). Bcrypt hashes generated with `openssl rand -base64 12` piped through `php -r 'echo password_hash(...)'` inside the openemr container. ACL group mapping confirmed by JOIN over users / gacl_aro / gacl_groups_aro_map / gacl_aro_groups:

- reception1 → Front Office (group id 14)
- cashier1 → Accounting (group id 15)
- doctor1 → Physicians (group id 13), `authorized=1` (provider, will appear in provider lists)
- lab1, pharm1, nurse1 → Clinicians (group id 12)

Passwords appended to `SECRETS.txt` as lines [4]–[9]. `sequences.id` bumped to 11 so future UI-driven adds don't collide.

**Note on schemas inspected:** existing ACL group rows (id 11–16) match what the playbook expects; no need to create new groups. Per-user wiring is 4 tables (`users`, `users_secure`, `gacl_aro`, `gacl_groups_aro_map`) — documented in `scripts/seed-users.sql` for future maintenance.

## Outstanding from Phase 5

- **5.2 Default Facility rename** (currently "Your Clinic Name Here") — deferred, placeholder is acceptable for now. Task #17 still pending.
- **24-hour time format** — `globals.time_display_format=1` set in DB but UI still renders calendar slots and date pickers as 12h. Needs investigation: there may be a calendar-specific setting, or the global needs a particular value other than '1'. Task #18 still pending.

## Phase 5.2 — Default Facility rename  ✅  2026-05-29
Renamed facility id=3 to "Clinic — OPD", Dar es Salaam, +255 000 000 000, country_code TZ via `scripts/seed-phase-5and8.sql`. Placeholder values — clinic owner to fill the real name.

## Phase 5 — 24h time format  ✅  2026-05-29
**Important correction:** OpenEMR's `time_display_format` semantics are `0=24h`, `1=12h` (verified by reading `library/globals.inc.php`). Original docs had this reversed. Updated:
- DB global flipped to `0`
- `docker-compose.override.yml` updated so future first-boot uses `0`
- Calendar slots and date pickers now render 24h

## Phase 8.1 — OPD apptstat statuses  ✅  2026-05-29
Added 7 statuses with colors (RG/PD/WD/WL/LB/WP/CM) via `seed-phase-5and8.sql`. Existing OpenEMR defaults left alone.

## Phase 8.3 — Tanzanian payment methods  ✅  2026-05-29
Added: M-Pesa, Tigo Pesa, Airtel Money, Halopesa, Bank Transfer, NHIF, Other Insurance. Existing Cash/Check/Credit Card/Electronic/Bank Draft left alone.

## Phase 9.0 — Medication CSV cleanup  ✅  2026-05-29
`scripts/clean_medication_csv.py` produces `medication_list.cleaned.csv` + `medication_cleanup_audit.txt`:
- 638 source rows → 638 cleaned rows, 1 garbage row dropped
- 596 importable (`import=true`), 42 flagged (`import=false`, blank generic name)
- Category ` -- Form` suffix split into separate `category` + `form` columns
- Form detected from category suffix AND fallback inference from drug name ("caps", "tabs", "syrup", etc.)
- Prices `TSh 1,234.50` parsed to numeric `1234.50`
- CSV-aware so embedded-comma rows (e.g. "Aspirin, paracetamol,and caffein") preserved correctly

## Phase 9 — Pharmacy templates bulk import  ✅  2026-05-29
`scripts/build_drug_seed_sql.py` reads cleaned CSV and emits `scripts/seed-drugs.sql`. Applied:
- 7 new `drug_form` list_options (syrup, gel, injection, suppository, pessary, spray, lotion) — IDs 13–19
- `inhouse_pharmacy` global set to 1
- 596 INSERTs into `drugs` table; `consumable=1` for the few syringe rows
- Form distribution: Tablet 212, Syrup 74, Cream 41, Suspension 36, Injection 33, Capsule 32, Gel 27, Drops 20, Ointment 11, smaller forms 7; 106 templates left with form='0' (mostly bulk consumables like Vitamin/Supplement entries with no explicit form)
- Inventory is empty — clinic admin must enter Purchase transactions per drug (lot, expiry, qty, unit cost) before dispensing works

## Phase 7 — OPD Visit Note LBF  ✅  2026-05-29
`scripts/seed-lbf-opd-visit.sql` creates the Layout-Based Form:
- 4 groups (Chief Complaint, History, Examination, Diagnosis & Notes) in `layout_group_properties`
- 9 fields in `layout_options` with proper data types (textarea=3, textbox list=22, billing-codes/ICD10=15) and uor flags (required=2, optional=1)
- Registered as a Clinical encounter form (id=26, directory=`LBFopd_visit`)
- Verified by `doctor1` opening an encounter and adding "OPD Visit Note"
- **Caveat**: ICD-10 diagnosis search returns no results until ICD-10 codes are imported (Admin → Other → External Data Loads → External ICD Import). Code structure is correct; just missing data.

## Phase 11 — Docker-aware backup  ✅  2026-05-29
- `scripts/backup.sh` — `docker compose exec mariadb-dump` for DB + `docker run --rm alpine tar` of `sitesvolume`. Reads DB password from SECRETS.txt at runtime. Bundles both compose files. Rotates ~/openemr-backups/ after 14 days.
- `scripts/com.openemr.backup.plist` — launchd schedule for 23:30 daily.
- `scripts/install-backup-schedule.sh` — one-liner to copy plist to ~/Library/LaunchAgents/ and launchctl load.
- Tested: 17 MB DB dump + 87 KB sites.tar.gz produced in `~/openemr-backups/2026-05-29_1912/`.

## Phase 13 — README hand-off  ✅  2026-05-29
- Upstream OpenEMR `README.md` preserved as `OPENEMR_README.md` (gitignored).
- Wrote project `README.md`: URLs, stack control, users, preconfigured state, what's still TODO, backups, restore test, known limitations, file inventory, reset procedure.

## ICD-10 codes  ✅  2026-05-29

User imported via Admin → Coding → External Data Loads → ICD10. `icd10_dx_order_code` has 98,186 diagnosis codes, `icd10_pcs_order_code` has 79,115 procedure codes. Malaria codes (B50–B54) confirmed present. Diagnosis search in the OPD Visit Note LBF should now return results.

## Initial Purchase transactions (essential meds)  ✅  2026-05-29

`scripts/seed-initial-stock.sql` seeded 18 drug_inventory rows at warehouse `onsite` with placeholder lot `SEED-2026-NNN`, expiry 2027-12-31, manufacturer "Generic". Drugs covered:
- Artemether-Lumefantrine 20/120mg (200 units — required for the Phase 12 malaria smoke test)
- Paracetamol 500mg tabs (1000)
- Metronidazole 200mg, Ciprofloxacin 500mg, Doxycycline 100mg, Albendazole 400mg
- Amlodipine 5mg, Atenolol 50mg, Metformin 500mg, Omeprazole 20mg
- Diclofenac 50mg, Ibuprofen 200mg + syrup, Salbutamol syrup + inhaler
- Folic acid, Ferrous sulfate+folic acid, Diazepam 5mg

Clinic admin should replace SEED-2026-* lots with real ones from the first PO.

## Demographics registration form trimmed for OPD  ✅  2026-05-29
`scripts/seed-demographics-layout.sql` (idempotent) sets `uor=0` on the US/HIPAA/registry-specific DEM fields, taking the new-patient/registration form from ~90 fields to **18 visible**: name (fname/lname required + mname), DOB, sex, Registration No. (pubpid), marital status; address/city/region + mobile phone + emergency contact & phone; assigned provider; referral source; and a minimal guardian block (name/relationship/phone). The Employer and Misc sections now render no header (all fields hidden). `state`→"Region", `pubpid`→"Registration No." relabels applied. Insurance reduced to **primary only** via `insurance_only_one=1` (DB global + added to `docker-compose.override.yml` for persistence across a DB reset). Hiding is non-destructive (data stays in `patient_data`). Pre-change uor snapshot saved to `scripts/.dem-layout-pre-seed.tsv` for rollback. Scope was Demographics only — New Encounter and History (HIS) layouts left untouched. Container not recreated (DB global already live; override env only re-applies on fresh install). UI round-trip with TEST patient still to be eyeballed by the user via Reception login.

## Phase 6 login fix — missing `groups` rows  ✅  2026-05-30
The six role users could not log in ("Invalid username or password") despite correct passwords. Root cause: login (`src/Common/Auth/AuthUtils.php` → `UserService::getAuthGroupForUser`) requires a row in the legacy **`groups`** table (`groups.user`=username, `groups.name`=authorization group). The original `seed-users.sql` wired `users`/`users_secure`/`gacl_*` but never the `groups` table, and its verify query only joined the `gacl_*` tables, so the gap was invisible. The audit `log` showed the real reason (base64-decoded): "failure: … user not found in a group". Fixed: inserted `('Default', <user>)` for all six (matching `admin`); patched `seed-users.sql` to include the `groups` block + a verify column so a DB reset stays correct. Passwords in `SECRETS.txt` were correct all along (confirmed via `password_verify` and a full `AuthUtils::confirmPassword()` run → SUCCESS). The `gacl_*` permission mapping was already correct and is unchanged.

## Phase 12 pre-flight — Lab leg enabled + test script  ✅  2026-05-30
Prep for the end-to-end smoke test surfaced two blockers on the Lab leg, now fixed:
- **Lab orders had nothing to order** — `procedure_providers`/`procedure_type` were empty. `scripts/seed-lab-procedures.sql` (idempotent, tagged `notes='OPD-SEED'`) seeds one in-house provider "Clinic Laboratory" (ppid=1) + 8 orderable `ord` tests under a `grp` parent, codes reusing the LAB-* service codes (mRDT, BS, CBC, UA, Stool, RBS, PT, HIV).
- **lab1 couldn't enter/sign results** — `orders_results.php` gates on `patients/sign`, which Clinicians lacks. Per user decision (avoid widening the whole group), `scripts/seed-lab-acl.php` creates a dedicated **Lab Technicians** ARO group (id 17) granting only `patients/sign` and adds `lab1` to it (kept in Clinicians too). Verified: `lab1` sign=ALLOW, `nurse1`/`pharm1`=deny. Uses the supported gacl API (`GaclApi::add_group`/`add_acl`, `AclExtended::addUserAros`), so the cache is handled and it replays on a DB reset.
- Readiness gate green: 1 provider, 8 orderable tests, 3 forms active (OPD note/Procedure Order/Fee Sheet), 18 drugs in stock, 5 role users login-ready.
- Test artifact: **`docs/smoke-test-findings.md`** — an 8-step, per-station, fill-in-as-you-go checklist (login → navigate → do → expect → notes/change-needed) plus a "known design notes" section. The actual click-through is still to be run by the user.

## OPD workflow v2 — nurse triage, pharmacist-only dispensing, registration card  ✅  2026-05-30
Three workflow changes after the pilot review (flow is now Reception → Cashier → **Nurse triage** → Doctor → Lab → Pharmacy → Discharge):
- **Nurse triage step** — `scripts/seed-triage-workflow.sql` (idempotent): added `apptstat` status **TR** "Triaged (Awaiting Doctor)" (seq 215); marked **RG** as a check-in (`toggle_setting_1=1`) so registering auto-creates the encounter; marked **CM** as a check-out (`toggle_setting_2=1`) + relabel "Completed / Discharged"; relabel **PD** → "Paid (Awaiting Triage)"; set `checkout_roll_off=1` so discharged patients roll off the active board. No code: nurse records the already-active **Vitals** form, sets the appointment Provider to route to a doctor, and the **Patient Flow Board** auto-scopes to the logged-in provider (filters by `pc_aid`), so the doctor sees their queue and discharge (CM) shrinks it.
- **Pharmacist-only dispensing** — `scripts/seed-pharmacy-acl.php` (supported gacl API, idempotent): created dedicated **Pharmacy** ARO group (id 18) granting `admin/drugs`, added `pharm1`; removed the `admin/drugs` ACO from the Clinicians (acl 18) and Physicians (acl 14) ACLs. Verified `aclCheckCore` + HTTP: `pharm1`/`admin` ALLOW; `doctor1`/`nurse1`/`lab1` deny; `drug_inventory.php` REACHED by pharm1, Not Authorized for doctor1/nurse1.
- **Registration ID card** — new `interface/patient_file/registration_card.php` renders a printable card (clinic name, patient name, **Registration No.**=pubpid, DOB + Code128 barcode via the bundled `Barcode::gd`), auto-opened by a one-line hook added to `interface/new/new_comprehensive_save.php` after save. `pubpid` auto-generates and is a searchable finder column, so the printed No./barcode pulls the patient elsewhere. Verified via authenticated HTTP fetch (200, valid PNG barcode for MWA001).
- Manual click-through of the new steps still to be done by the user via the updated `docs/smoke-test-findings.md` (now 9 steps incl. the nurse).

## Payment Type field replaces insurance form  ✅  2026-05-30
For the pilot the clinic wants to track *how the bill is covered* (Cash / NHIF / CHF / …) rather than capture full insurance. `scripts/seed-payment-type.sql` (idempotent): created custom list `coverage_type` (Cash (Self-pay), NHIF, CHF/iCHF, Private Insurance, Employer/Company, Exempt/Free, Other); repurposed the spare list-backed column `patient_data.userlist1` as a **required "Payment Type"** dropdown on the DEM layout (group 1, after Birth Sex); set `simplified_demographics=1` to remove the whole Insurance section from registration (added to `docker-compose.override.yml`). No schema change. Verified via HTTP: field + options render on the new-patient form, insurance section gone. Reporting query (patients by Payment Type) is in the script footer; counts live in `patient_data.userlist1`. Note: this is a patient-level default — if per-visit accuracy is needed later it can move to the encounter/fee sheet.

## "Provider" field → "Doctor", clinicians-only  ✅  2026-05-30
`scripts/seed-doctor-field.sql` (idempotent): relabelled the DEM `providerID` field to **Doctor**, and cleared `users.authorized` on the `admin` account so only actual clinicians appear in provider/Doctor dropdowns. Provider lists (layout data_type 11) filter on `authorized=1`; nurse/reception/cashier/lab/pharmacy were already `authorized=0`. Verified via HTTP — the registration Doctor dropdown now offers only `Unassigned | Doctor One`. **Side effect (intended):** admin is no longer selectable as a provider anywhere (calendar, fee sheet, encounter); future doctors just need `authorized=1`.

## Region dropdown → Tanzania regions  ✅  2026-05-30
The Region field (DEM `state`, data_type 26) was bound to OpenEMR's built-in US `state` list. `scripts/seed-tz-regions.sql` (idempotent) adds a new `tz_region` list with Tanzania's **31 regions** (26 mainland + 5 Zanzibar), points the Region field's `list_id` at it, and sets the `state_list` global so facility forms match. Non-destructive (the US `state` list is left intact). Verified via HTTP — dropdown now shows Unassigned + 31 TZ regions, no US states. (Seed must be replayed after a DB reset, like the other seeds; not added to the override because the global depends on the seeded list existing.)

## Rebrand to "smartEMR" (look only)  ✅  2026-05-30
Login-page rebrand, no functionality change:
- **Name** → `openemr_name='smartEMR'`; old OpenEMR marketing tagline hidden (`scripts/seed-branding.sql`).
- **Logo** — iPAB monogram (`branding/ipab-logo-src.png`) made transparent via container ImageMagick (`-fuzz 18% -transparent white -trim`) and installed as the login + top-nav logo + favicon.
- **Login background** — `branding/login-bg.svg` (blue gradient + EKG line + faint cross pattern; matches iPAB blue), served as the login body background.
- **Footer link** — replaced the "Acknowledgments, Licensing and Certification" link with **"Maintained by iPAB"** → https://www.ipab.co.tz/.
- Image assets live in the `sitesvolume` (not the repo), so they are reinstalled by **`scripts/install-branding.sh`** (idempotent; re-run after a volume/DB reset). Source assets + installer are in `branding/` and `scripts/`.
- Verified via HTTP + a Selenium screenshot of the login page.
- **Core template edits (upgrade-fragile — reapply after an OpenEMR upgrade):** `templates/login/base.html.twig` (body background) and `templates/login/partials/html/acknowledgements.html.twig` (iPAB link). These are the only two core-file branding edits.

## Branding follow-ups — nav-logo home + English-only login  ✅  2026-05-30
- **Nav logo no longer opens an external site.** The top-bar logo (`interface/main/tabs/main.php:462`) linked to open-emr.org; now points to a new `interface/main/tabs/gohome.php` that issues a fresh single-use `token_main` and redirects to the tabbed app's landing (mirrors `main_screen.php`; auth-gated by globals.php). Verified: logged-in GET gohome.php → 302 → main.php?token_main=… → 200 landing.
- **Login limited to English for now.** The gating global `language_menu_login` isn't a real/settable global, so the selector is hidden via a one-line guard in `templates/login/partials/input/languages.html.twig` (`{% if false %}`). The hidden `languageChoice=1` (English) is still submitted. Re-enable by restoring `{% if displayLanguage %}`. **[SUPERSEDED 2026-05-30 — selector re-enabled with English+Swahili; see "Login-page language selector" entry below. Note: `language_menu_login` IS settable indirectly — it's derived true when `language_menu_showall` is on OR >1 language is in `language_menu_other` (interface/globals.php ~L548).]**
- **Added core edits to track for upgrades:** `interface/main/tabs/main.php` (logo href), `templates/login/partials/input/languages.html.twig` (language guard), and new file `interface/main/tabs/gohome.php`.
- **Favicon → iPAB logo.** OpenEMR's favicon loader (`src/Core/Header.php:136`) looks for a file literally named `favicon.ico` (not `logo.png`), so the earlier file was ignored. `scripts/install-branding.sh` now generates a multi-size `favicon.ico` (16/32/48/64) from the transparent logo into `core/favicon/`. Verified served as `image/x-icon`. (Browsers cache favicons hard — a hard refresh / new tab may be needed to see it.)

## smartEMR end-user guide (Word doc)  ✅  2026-05-30
Produced `smartEMR_User_Guide.docx` — a role-based, step-by-step OPD guide (English with Swahili hints) covering Getting Started + the 7 stations (Reception → Cashier → Nurse → Doctor → Lab → Pharmacy → Discharge), plus the Patient Flow Board status legend, a quick-reference table, and a troubleshooting/FAQ. 14 real browser screenshots captured live: `scripts/capture-guide-screenshots.py` (standalone Selenium → the dev-easy `selenium` grid at `localhost:4444`; browser reaches the app via the internal Docker host `http://openemr`, NOT localhost:8300). It reads role passwords from `SECRETS.txt` at runtime, sets patient/encounter session context via `demographics.php?set_pid=2&set_encounterid=12` + `encounter_top.php?set_encounter=12`, and uses only the TEST patient; PNGs land in `docs/guide-screenshots/`. The .docx is built by `scripts/build-user-guide.js` (docx-js) using an isolated tooling install at `scripts/guide-tools/` so OpenEMR's root `package.json`/`package-lock.json` stay untouched (both gitignored: `scripts/guide-tools/node_modules`). Verified: valid OOXML, all 14 images embedded, correct heading structure, no passwords or real patient names in the body text. Notable finding while capturing: the **Cashier (Accounting) role lacks the Fee Sheet form ACL** (`aclCheckForm('fee_sheet')` → "Not authorized"); the guide documents the Fee Sheet step with a note to enable it for the Cashier role, and the cashier can still take payment via Checkout. Optional refinement: re-render the doc to PDF for a visual pass once LibreOffice is available (not installed on this host).

## Outstanding (deferred)

- **Phase 12 — End-to-end smoke test** with the 8-step TEST patient walkthrough. Requires the user to click through Reception → Cashier → Doctor → Lab → Pharmacy in the browser. Not yet run.
- **ICD-10 import** — Admin UI step, ~30 sec, prerequisite for diagnosis search to work.
- **Initial drug Purchase transactions** — required before dispensing actually deducts stock.
- **Lab test reference ranges configuration** — Admin → Procedures → Configure.
- **`/security-review` pass** before any LAN/internet exposure.
- **Real clinic name** in Default Facility.

## Quick stack-state snapshot

```
$ docker compose ps
NAME                            STATE
development-easy-openemr-1      Up (healthy)
development-easy-mysql-1        Up (healthy)
development-easy-phpmyadmin-1   Up
development-easy-mailpit-1      Up (healthy)
development-easy-selenium-1     Up (healthy)
development-easy-couchdb-1      Up
development-easy-openldap-1     Up

DB tables: 283
Drug templates: 596
Service codes: 13
Users: 7 (admin + 6 role users)
Patients: 1 (TEST Mwajuma Hassan, MWA001)
```

## Swahili (Kiswahili) OPD-subset translation — artifacts ready, import PENDING  2026-05-30

Background: OpenEMR ships ~13,234 translatable constants and the pilot DB already
has the 37-language official set, but **Swahili is absent** (not in OpenEMR's
official set, daily build, or wiki — no off-the-shelf pack exists). Per agreed
scope we translated the **OPD workflow subset** only; everything else falls back
to English automatically (the English constant is returned when no definition
exists for the active language).

**Produced (artifacts only — NOT yet in the DB).** Extracted English constants
from the high-traffic OPD surfaces (navigation menu, registration/demographics,
encounter/clinical, fees/cashier, lab/orders, pharmacy/dispensary, universal
actions), validated each against `lang_constants` (336 matched as real
constants), and hand-translated **229** into Kiswahili sanifu with a consistent
clinical glossary (Mgonjwa, Miadi, Agizo la Dawa, Malipo, Utambuzi, Maabara,
Duka la Dawa, …). Repo: `i18n/swahili_opd.csv` (source of truth, 229 rows),
`i18n/build_swahili_opd.py` (regenerates CSV), `i18n/generate_import_sql.py`
(emits idempotent SQL — not used; UI path chosen).

**Import status: PENDING (Admin UI path).** DB unchanged (still 37 languages, no
`sw` row). A direct SQL-as-root import was intentionally blocked by the safety
guard. To load: Administration → Language → **Add Language** (`sw` / `Swahili`,
LTR), then **Load from CSV** → select Swahili, upload `i18n/swahili_opd.csv`, set
constant column = `constant` and definition column = `definition`, **Preview
Changes** → **Commit Changes**. (CSV import writes definitions directly via
`verify_translation()`; the Manage→Synchronize step is only for the official
SQL-dump path and is NOT needed here.)

**After import.** `language_menu_login=all`, so Swahili appears in the login
dropdown once added; `language_default` stays English. A Swahili-speaking staff
member should review terms in-app (login with UI language = Swahili; walk
Reception → Cashier → Doctor/LBF → Lab → Pharmacy as TEST Mwajuma Hassan);
corrections via Edit Definitions or by editing `i18n/swahili_opd.csv` and
re-importing. Optional: extend to the custom OPD LBF field labels once finalised,
and to remaining admin/report labels if desired.

## Login-page language selector (English default + Swahili)  ✅  2026-05-30

Re-enabled the login-page language dropdown (previously hard-hidden — see the
SUPERSEDED note above) and restricted it to exactly two options:
**Default - English (Standard)** (pre-selected) and **Swahili**.

**Changes.**
- `templates/login/partials/input/languages.html.twig`: `{% if false %}` →
  `{% if displayLanguage %}`, plus an `{% if l.lang_id != defaultLangID %}`
  guard in the loop so the default (English) is not listed twice (it already
  shows as the "Default - …" row). Host bind-mounted → live immediately, no
  rebuild.
- DB globals via idempotent `scripts/configure-login-language-menu.sql`:
  `language_menu_showall=0`; `language_menu_other` rows = `English (Standard)`
  + `Swahili`; `language_default=English (Standard)`. Two entries are needed so
  the derived `language_menu_login` flag (interface/globals.php ~L548: true when
  showall OR count>1) turns the menu on; the template guard then de-dupes
  English.

**Verified.** `curl http://localhost:8300/interface/login/login.php?site=default`
renders `<select name="languageChoice">` with exactly two options:
`<option value="1" selected>Default - English (Standard)</option>` and
`<option value="38">Swahili</option>` — no third language, no English duplicate.
DB confirms the four globals rows. Depends on the Swahili language row (lang_id
38, 229 defs) from the translation import.

**Upgrade-tracking / reproducibility.** Core file edited (track for upgrades):
`templates/login/partials/input/languages.html.twig`. The override compose only
manages `language_default`, so these menu globals survive reboots; after a DB
volume rebuild, re-run the Swahili import then
`scripts/configure-login-language-menu.sql`.

## OPD re-architecture — Phase 1: Clinic data model  ✅  2026-05-30
Decision (with user): **clinics = appointment categories** (one Health Facility;
each clinic is a `pc_cattype=0` row in `openemr_postcalendar_categories`, carried on
the encounter via `pc_catid`). `scripts/seed-clinics.sql` (idempotent, id-stable —
uses `INSERT … ON DUPLICATE KEY UPDATE` on the unique `pc_constant_id` so encounters'
`pc_catid` never renumbers) seeds 5 starter clinics — General OPD (GP, pc_catid 16),
Gynaecology (17), Paediatrics (18), RCH/Antenatal (19), Dental (20) — *clinic owner to
confirm/extend*. Each clinic maps to its own consultation fee code; added `REG`
(Registration 2,000) + `CONS-GYN` 15k, `CONS-PAED` 10k, `CONS-RCH` 5k, `CONS-DENT` 15k
to `codes`/`prices` (reusing existing `CONS-GP` 10k). NB `codes` has no unique key on
`code`, so the seed uses insert-if-absent + UPDATE (keeps `codes.id` stable for
`prices.pr_id`). Verified idempotent (pc_catids stable, no code dupes).

## OPD re-architecture — Phase 2: oe-module-opd skeleton + install  ✅  2026-05-30
Built the upgrade-safe custom module `interface/modules/custom_modules/oe-module-opd/`
(decision: new logic lives in a module, config in idempotent seeds). Files: `info.txt`,
`version.php`, `composer.json`, `moduleConfig.php`, `openemr.bootstrap.php`,
`src/Bootstrap.php` (MenuEvent subscriber → admin-only "OPD Workflow" entry; per-role
queue links deferred to Phase 7 menu JSONs to keep exclusivity clean),
`src/Service/OpdClinicService.php`, `public/opd_admin.php`, `sql/install.sql` +
`sql/uninstall.sql` (tables `opd_clinic_config`, `opd_provider_clinic`).
Reproducible install via `scripts/install-opd-module.php` (mirrors Manage Modules:
registers the `modules` row type=0/active=1/sql_run=1 + `module_acl_sections`, runs
install.sql stripping `#IfNotTable` directives — `CREATE TABLE IF NOT EXISTS` is already
idempotent). Note: `sqlGetLastInsertId()` is unreliable for `modules`, so the script
re-queries `mod_id` (=19). Config data in `scripts/seed-opd-config.sql`: clinic→code map
(all 5) + doctor1 granted all 5 clinics. Verified via in-container probe: namespace
autoloads, `MENU_UPDATE` listener attaches, `OpdClinicService::getClinics()` returns the
5 priced clinics, provider grants resolve; container logs clean (no fatals).

## OPD re-architecture — Phase 3: Reception start-visit + billing  ✅  2026-05-30
`OpdVisitService::startVisit(pid, clinicCatid, providerId)` orchestrates the native
helpers: `AppointmentService::insert` (today's appt in the clinic category),
`todaysEncounterCheck` (ensures one encounter/day carrying `pc_catid`=clinic),
`AppointmentService::updateAppointmentStatus`→`PatientTrackerService::manage_tracker_status`
(board entry at **RG**), and `BillingUtilities::addBilling` (Registration once per patient
lifetime + clinic consultation once per encounter). Screen `public/reception_register.php`:
patient search → clinic + optional doctor → "Start visit & bill" with CSRF + summary.
**Bug found & fixed (would have hit the real page too):** `library/encounter_events.inc.php`
sets the top-level `$today` only when included at *global* scope; required from the service
constructor it left the global `$today` empty, so `todaysEncounterIf()` matched nothing and
created a **duplicate encounter every request**. Fix: the constructor sets `global $today`.
Verified across 3 separate processes (= 3 requests) → all converge on one encounter, one
appointment, REG×1 + CONS-GYN×1; encounter has pc_catid=17 (Gynaecology), provider 7,
status RG. Test fixture left in place (Mwajuma, encounter at RG/unpaid) for Phase 4.
Browser click-through deferred to the Phase 8 `/verify`.

## OPD re-architecture — Phase 4: Payment gating + cashier  ✅  2026-05-30
`OpdGatingService`: `getVisitBalance`/`isVisitPaid` derive paid state from the encounter
balance (`get_patient_balance` = billing − ar_activity − drug_sales — the same figure the
native AR screens show); `recordPayment` mirrors the native front-desk patient payment in
`front_payment.php` (ar_session + ar_activity account_code 'PCP' + a `payments`-table receipt
with method) so accounting + the front-receipts report stay consistent — **no bespoke money
math**; `activateIfPaid` advances RG→**PD** (Paid/Awaiting Triage) once balance ≤ 0. Screen
`public/cashier_checkout.php`: today's RG/PD worklist with charges + balance, method+amount
collect form (CSRF), auto-advances already-paid RG (e.g. paid via native Checkout) to PD.
Verified on the Mwajuma fixture: balance 17,000 (REG 2,000 + CONS-GYN 15,000) → unpaid, not
activated, stays RG; after M-Pesa 17,000 → balance 0, paid, RG→PD on both appointment and
tracker; worklist lists her at PD. The triage/doctor queues (Phase 5) also self-filter on
isVisitPaid as defence-in-depth, so an unpaid patient can never reach the nurse.

## OPD re-architecture — Phase 5: Triage + per-clinic doctor queues  ✅  2026-05-30
`public/triage_queue.php` (nurse): lists today's PAID patients awaiting triage (status PD,
self-filtered on isVisitPaid so unpaid never show); "Open chart" loads the encounter for
Vitals/notes via the native flow-board pattern (`top.RTop.location =
demographics.php?set_pid=…&set_encounterid=…` after `restoreSession`); "Triaged → Doctor"
optionally assigns a provider and advances to **TR**. `public/doctor_queue.php` (doctor, and
the doctor landing page): clinic dropdown limited to the provider's granted clinics
(`opd_provider_clinic`); lists paid+triaged (TR) patients in the selected clinic (pc_catid);
"Open & start consult" sets **WD** and opens the encounter. Added `OpdVisitService::setStatus`
/`assignProvider` helpers. Verified the full chain on Mwajuma: triage shows her (PD/paid) →
nurse sends to doctor1 (TR) → triage empties → she appears in the **Gynaecology** queue (1)
but **not** the GP queue (0) — per-clinic scoping confirmed; doctor1 granted all 5 clinics
(a doctor with fewer grants sees fewer clinics in the dropdown). She now sits at TR/WD as the
Phase 6 fixture.

## OPD re-architecture — Phase 6: Encounter capture + summary  ✅  2026-05-30
Expanded the OPD Visit Note LBF (`scripts/seed-lbf-opd-visit.sql`, idempotent) from 4→**5
groups / 11 fields**: split the single Diagnosis into **Provisional Diagnosis** (ICD-10, req)
and **Final Diagnosis** (ICD-10, opt), added **Investigations Requested/Done** (textarea), and
renamed the plan field; HPI/history/PMH/exam unchanged. (Old `diagnosis` field_id retired —
any pre-pilot lbf_data for it is orphaned; only test data existed.) The native encounter
summary already aggregates the LBF + Vitals + Procedure Orders/results (= investigations done);
the **one** gap — medications dispensed — is filled **without a core edit** via
`OpdEncounterService::getDispensedMeds` + a `Bootstrap` listener on
`EncounterFormsListRenderEvent::EVENT_SECTION_RENDER_POST` that appends a "Medications dispensed
(this encounter)" table from `drug_sales`. Verified: service returns the dispensed row, listener
attaches exactly once on auto-load (renders once per encounter view), HTML escapes correctly.
So opening an encounter now shows HPI, history, PMH, exam, provisional dx, investigations, final
dx and medications dispensed — requirement 10 met.

## OPD re-architecture — Phase 7: Role-exclusive menus + feature globals  ✅  2026-05-30
Six lean per-role menus authored in `interface/modules/custom_modules/oe-module-opd/menus/`
(authoritative, in-repo) and installed into the `sitesvolume` by `scripts/install-role-menus.sh`
(sites/ is a Docker volume + gitignored, so menus need an installer like branding does — re-run
after a sites-volume reset). `scripts/seed-role-menus.sql` sets `users.main_menu_role` per user
(reception1→opd_reception.json … pharm1→opd_pharmacy.json; admin keeps `standard`). Verified
each role's `MainMenuRole::getMenu()` renders ONLY its own group(s) + File — no Administration,
no clinical/billing leak, and the module's admin-menu injection correctly does not appear (these
menus have no Admin group). `scripts/seed-feature-globals.sql` disables non-OPD areas (patient
portal, group therapy, immunizations, CDR/CQM/AMC, phpMyAdmin link); calendar, in-house pharmacy
and fees kept (in scope).
**ACL exclusivity (URL layer):** strong where it matters — `acct/bill` (cashier) and
`admin/drugs` (pharmacy) resolve ALLOW for exactly one role; reception/cashier are denied all
clinical ACOs. **Known limitation (for the security review):** the clinical ACOs `encounters/notes`
and `patients/lab` are shared across nurse/doctor/lab/pharm (the Clinicians+Physicians base), and
`patients/demo` is universal, so the menus enforce exclusivity for those roles but the screens
remain URL-reachable within the clinical group. Tightening would need per-role ACO splits.
**Deferred:** auto-landing on the role's first screen at login (the lean menu makes it the first,
prominent item); can add a post-login redirect later.

## OPD re-architecture — Phase 8: Hardening  ✅  2026-05-30
**Security audit (manual — repo not under git, so `/security-review`'s diff has nothing to
compare; recommend running it after `git init`).** Module code scan: all SQL is parameterized
(no string interpolation), all output escaped (`text`/`attr`/`xlt`/`js_escape`/`attr_js`), every
POST handler verifies CSRF, every `public/*.php` enforces `globals.php` auth + `aclCheckCore`,
`$_SERVER['PHP_SELF']` only emitted via `attr()`. Money posting mirrors native front_payment
(ar_session + ar_activity + payments receipt) inside a transaction — no bespoke math. Known
gap documented: clinical-role ACO overlap (menu-enforced, not URL-enforced for nurse/doctor/
lab/pharm). Container logs clean (no fatals/uncaught across the whole build). Final backup taken
(`~/openemr-backups/2026-05-30_1734`, includes the sites volume with the new menus). README
updated with the role-based OPD workflow + reproduce-after-reset order. **Browser click-through**
(`/verify` / `/run`) is the one item left for the user — every phase was verified at the
service/DB layer via in-container probes (curl can't hold an OpenEMR session); a Selenium pass
through Reception → Cashier → Nurse → Doctor → Lab → Pharmacy as TEST Mwajuma Hassan is the
final sign-off.

### Reproduce-after-reset order (all idempotent)
`seed-clinics.sql` → `php scripts/install-opd-module.php` → `seed-opd-config.sql` →
`seed-lbf-opd-visit.sql` → `seed-role-menus.sql` → `seed-feature-globals.sql` →
`./scripts/install-role-menus.sh`.

## OPD re-architecture — browser-verification fix: triage/doctor queue JS dump  ✅  2026-05-30
First browser pass (nurse1 → OPD Triage Queue) showed a wall of raw JavaScript printed as text
above the heading. Cause: `library/restoreSession.php` emits raw JS with **no `<script>` wrapper**
(meant to be included inside one, as `patient_tracker.php:145` does); `triage_queue.php` and
`doctor_queue.php` `require`d it at the top of the PHP file, outside any script tag. Fix: removed
the `require_once("$srcdir/restoreSession.php")` from both (their `topatient()` only calls
`top.restoreSession()`, already defined by the parent frame `main.php:106`, so the local include
was dead weight), and guarded the call as `if (top.restoreSession) { top.restoreSession(); }`.
Both lint clean; bind-mounted so live immediately. Menu/role-exclusivity confirmed correct in the
same screenshot (nurse sees only Triage + File). Secondary cosmetic note: default Calendar/Message
Center startup tabs still open for the nurse (ACL-gated; not menu items) — left as-is.

## OPD re-architecture — disable double-click "Nation Notes" template popup  ✅  2026-05-30
Browser pass on the OPD Visit Note: double-clicking a textarea (e.g. Investigations) opened the
empty Nation Notes **text-template** picker (`library/custom_template/custom_template.php`). This
is a stock OpenEMR feature — global `text_templates_enabled` (default 1) = "double-click any
encounter textarea to pick a template" (`library/js/CustomTemplateApi.js`). No templates are
configured, so it was pure noise and broke double-click-to-select-a-word while typing notes. Set
`text_templates_enabled=0` (added to `scripts/seed-feature-globals.sql`); reversible anytime via
Admin → Config → Features → "Enable Text Templates in Encounter Forms". Takes effect on next form
load. Not caused by our changes — affects all encounter textareas.

## OPD re-architecture — chain registration → clinic allocation; reception is clinic-only  ✅  2026-05-30
Per pilot feedback: registering a new patient should flow straight into the OPD visit (no
re-finding the patient under "Start OPD Visit"), and reception should allocate to a **clinic
only** (the triage nurse assigns the doctor).
- **Chained flow:** `interface/new/new_comprehensive_save.php` post-save hook now redirects the
  main window to `…/oe-module-opd/public/reception_register.php?pid=<newpid>&is_new=1` (the
  registration-card popup still opens). Reception lands on the clinic-allocation step for the
  just-created patient, picks a clinic, and `OpdVisitService::startVisit` auto-bills Registration
  (first visit) + the clinic consultation. (Core-file edit — same already-tracked hook.)
- **Clinic-only reception:** removed the Doctor dropdown + provider handling from
  `reception_register.php` (now calls `startVisit(pid, clinic, null)`; button relabelled
  "Allocate to clinic & bill", with note "The triage nurse will assign the doctor"). Also hid the
  patient-level **Doctor** field from the registration form (`layout_options` DEM `providerID`
  `uor=0`, added to `scripts/seed-doctor-field.sql`; non-destructive, reversible). The encounter
  provider stays unassigned (provider_id 0) until the nurse routes the patient at triage.
Both files lint clean; bind-mounted so live immediately. `startVisit(…, null)` was already verified
(encounter created with provider 0; nurse assigns later via the triage "Send to doctor" step).

## OPD re-architecture — role-scoped patient charts + cashier ACL fix  ✅  2026-05-30
Feedback: opening a patient from the finder showed every role the full clinical chart (view/write
medical records). Root cause: the default **patient-menu** items
(`interface/main/tabs/menu/menus/patient_menus/standard.json`) carry **no `acl_req`**, so they show
for all roles regardless of permission — a menu-surface leak, not an ACL hole (Front Office has no
`patients/med|notes|rx|lab`). Fixed by mirroring the Phase-7 main-menu approach for the patient
chart (`src/Menu/PatientMenuRole.php` + `users.patient_menu_role` + JSONs in
`sites/.../custom_menus/patient_menus/`):
- 5 lean patient menus in `oe-module-opd/menus/patient_menus/`: reception = Dashboard + **Start
  Visit** (→ reception_register.php?pid=); cashier = Dashboard + **Outstanding Bills** (native
  Ledger, has acct/rep) + **Take Payment** + **Checkout**; nurse = Dashboard + History (records
  vitals/triage on the encounter opened from the queue); lab = Dashboard + **Patient Results**;
  pharmacy = Dashboard. doctor/admin keep `standard` (full). Installed into the sites volume by the
  extended `scripts/install-role-menus.sh` (now copies `patient_menus/` too); assigned via
  `scripts/seed-role-menus.sql` (`patient_menu_role` column). Verified each role's
  `PatientMenuRole::getMenu()` renders exactly its allowed tabs.
- **Reception outstanding bills**: shown on `reception_register.php` via
  `OpdGatingService::getPatientBalance()` (= `get_patient_balance`) — no financial ACL granted to
  reception; the balance appears on the Start-Visit screen with "direct the patient to the cashier".
- **Cashier ACL hardening**: `scripts/seed-cashier-acl.php` (idempotent, `GaclApi::shift_acl`)
  removed `encounters/auth_a|coding_a|date_a` from the Accounting "write" ACL (id 26) — cashier had
  write to ANY encounter (authorize/code/redate). Verified (fresh process): cashier1 now DENY on all
  three, still ALLOW `acct/bill` + `patients/demo`; doctor1 unaffected. Tracked core-data change.
- Also removed a stray test `drug_sales` row (Phase-6 probe leftover, fee 500) inflating Mwajuma's
  balance; her balance is now 0. Container logs clean.

## OPD re-architecture — active-visit lifecycle (multi-day, discharge-ended)  ✅  2026-05-30
Feedback: a visit must be a **lifecycle** open until **discharge** (CM), not per-day — a patient
with pending results who returns next day must NOT re-register, stays in the doctor's queue, and
the doctor keeps editing the same encounter. Replaced the per-day model with a lifecycle one
(decisions: **doctor discharges**; reception blocked for the **same clinic**).
- `OpdVisitService`: `ACTIVE_STATUSES = {RG,PD,TR,WD,WL,LB,WP}`, `STATUS_DISCHARGED='CM'`,
  `ACTIVE_LOOKBACK_DAYS=30` (hygiene bound). New `getActiveVisit(pid,clinic)`,
  `getActiveVisits(pid)`, `discharge(eid,enc)` (→ CM via native checkout/roll-off + sets
  `form_encounter.date_end`). `startVisit` now **guards**: if an active visit exists in the clinic
  it returns `already_active` with no new appointment/encounter/billing.
- Reception (`reception_register.php`): shows the patient's active visit(s) when selected; on a
  duplicate, shows "Patient already has an active visit in <clinic> … no new registration needed"
  instead of a billing summary.
- Queues are now lifecycle-based (dropped `pc_eventDate = CURDATE()`, bounded by the 30-day
  lookback): cashier = RG/PD; triage = PD; **doctor = TR/WD/WL/LB/WP** for the clinic with a
  **Status** column and a **Discharge** button (→ `discharge`). Fixed the real bug where the doctor
  queue filtered `TR` only, so a patient vanished the instant the doctor started (`WD`).
- The encounter persists across days (created once, reused); the doctor opens the same encounter
  from the queue and keeps editing — no second consultation charge.
Verified end-to-end on the test patient: TR→(Open)WD stays in queue; same-clinic re-registration
blocked, other clinic allowed; backdated "next day" still appears in the doctor queue and still
blocks reception; Discharge → CM removes from queue, sets date_end, and unblocks reception. Logs
clean. (Test patient left discharged/CM — a valid completed visit in her history.)

## OPD — doctor patient-chart menu + encounter edit-window  ✅  2026-05-30
Feedback: trim the doctor's patient chart and time-box editing. New
`opd_doctor_patient.json` (authoritative copy in `oe-module-opd/menus/patient_menus/`, installed to
the sites volume by the existing `scripts/install-role-menus.sh` glob) **hides Report / Documents /
Transactions**, **adds an Encounters tab** (→ the native "Visit History" `encounters.php?pid=`,
which already lists every visit and opens any on click), and keeps Dashboard / History / Assessments
/ Issues / Ledger / External Data. `doctor1.patient_menu_role` set to it (DB + `seed-role-menus.sql`
so it survives a reset). **Edit-window rule** (`OpdEncounterService::isEncounterEditableByDoctor`):
a doctor may edit only encounters where they are the **provider** (`form_encounter.provider_id`),
and only while the encounter is **either < 1 day old OR still open** (not discharged —
`date_end` empty). Once it is **both** older than a day **and** closed it is **view-only**. Enforced
in `interface/patient_file/encounter/forms.php` (gated only when `patient_menu_role =
opd_doctor_patient.json`, so admins/other roles are unaffected): per-form Edit button → "View only",
e-sign button suppressed, "Add New Form" menu hidden. Verified via Selenium (doctor1, TEST patient
pid 2): menu hides the three + shows Encounters; own fresh-but-closed enc → **Edit** (proves the OR
rule); own back-dated+closed enc → **View only**; another provider's enc (admin's) → **View only**
but still openable. Fixture (back-dated date) restored after. UI-level gate only — form save
endpoints not yet server-side gated (noted for the security review).

## OPD — lean encounter form menu + Chief-Complaint picker  ✅  2026-05-30
Feedback: trim the encounter "Add New Form" navbar and make complaint capture list-driven. A new
listener on `EncounterMenuEvent::MENU_RENDER` in `oe-module-opd/src/Bootstrap.php`
(`filterEncounterMenu`, registered at **priority -100** so it runs after `forms.php` populates the
menu) trims **Administrative → Fee Sheet only** and **Clinical → Vitals / OPD Visit Note / Clinical
Instructions only**; **Orders** and other categories untouched. Filters by registry `directory`
(language-independent; future forms in those two categories are hidden by default). **Admin/super is
exempt** (full menu retained for config/testing). No `registry.state` change, so Vitals etc. stay
enabled everywhere else — nurse triage unaffected. **OPD Visit Note** (`scripts/seed-lbf-opd-visit.sql`):
`chief_complaints` changed from free-text (data_type 22) to **Multiple Select List** (data_type 36)
bound to a new curated list `opd_complaint` (`scripts/seed-opd-complaint-list.sql`, ~28 common OPD
complaints, editable via Admin → Lists); added a free-text **`other_complaints`** field for anything
not listed. "Review of Systems Checks" (`reviewofs`) is a legacy hardcoded form with no reusable
list, hence the fresh curated list. Verified via Selenium: doctor1 & nurse1 see the lean menu (nurse
keeps Vitals); admin sees the full menu; the OPD Visit Note renders the multi-select with the curated
options + the free-text box. Seed scripts are the source of truth (re-run after a DB reset).

## OPD Visit Note UI — autocomplete complaints + accordion sections  ✅  2026-05-30
Feedback: make the multi-select Chief Complaints a single autocomplete field and turn the
checkbox-toggled sections into accordions. Added a **per-form LBF plugin**
`oe-module-opd/lbf/LBFopd_visit.plugin.php` (auto-loaded by `interface/forms/LBF/new.php` only for
LBFopd_visit; deployed into the `sitesvolume` at `sites/default/LBF/` by new
`scripts/install-lbf-plugins.sh` — re-run after a sites reset). Its `_javascript_onload()` hook:
(1) upgrades `chief_complaints` (still data_type 36 / list `opd_complaint`) to a **Select2 tag
picker** — blank with placeholder, autocompletes from the list as you type, **`tags:true`** so the
doctor can also add free-typed complaints, multiple removable chips (stored pipe-delimited); the
blank "Unassigned" option is stripped. (2) Converts each checkbox group
(`form_cb_lbfN` / `div_lbfN` + `divclick`) into a **click-to-collapse accordion header** with a
chevron (removes the checkbox; no core edit to new.php). The redundant **`other_complaints`** field
was **removed** from `scripts/seed-lbf-opd-visit.sql`, and all 5 groups set `grp_init_open=1` so the
accordion starts expanded. No changes to other LBF forms (plugin is form-scoped). Verified via
Selenium (doctor1): Chief Complaints is a Select2 widget with the curated options (Fever/Cough) and
no Unassigned/Other field; 5 accordion headers with chevrons, zero group checkboxes, header click
toggles its section. Text-template modal alert on the LBF page is unrelated (pre-existing).

## OPD Visit Note — suppress signer alert + lock Provider to logged-in doctor  ✅  2026-05-30
Feedback: "disable text templates" (the JS alert) and auto-select+lock the Provider. Investigation
showed the alert was **not** text templates (`text_templates_enabled` was already 0 in
`seed-feature-globals.sql`) — it came from the **portal signer**: `interface/forms/LBF/new.php`
calls `signer_head()` **unconditionally** for every LBF form, loading
`portal/sign/assets/signer_api.js`, whose startup `fetch(signer_modal.php)` gets HTML and parses it
as JSON → `alert("Modal Template Fetch:" + error)`. The OPD Visit Note has no signature field, so the
signer is unused here. Extended the `LBFopd_visit.plugin.php`: a `_javascript()` (head) hook wraps
`window.alert` to swallow only messages starting with `"Modal Template Fetch:"` (all other alerts
pass through); and the `_javascript_onload()` hook now **auto-selects the logged-in provider**
(`$_SESSION['authUserID']`) in `select[name=form_provider_id]` when blank, **disables** it (locked),
and adds a hidden `form_provider_id` mirror so the value still submits (the POST handler at new.php
updates `forms.provider_id`). Form-scoped; only LBFopd_visit (the only LBF a doctor adds) is affected.
Verified via Selenium (doctor1): no alert on load; Provider shows "Doctor One", disabled, with the
hidden mirror = 7. Re-deployed with `scripts/install-lbf-plugins.sh`.

## OPD encounter — read-only Patient Bill for the doctor (no billing)  ✅  2026-05-30
Feedback: the doctor must not bill but should see the patient's bill; the encounter Administrative
"Fee Sheet" should be a simple read-only bill like the Visit History billing view. New page
`oe-module-opd/public/encounter_bill_view.php` renders the current visit's charges read-only —
Code/Item, Charge, Paid, Adjustment, Balance + a Total row — using the same data/maths as
`interface/patient_file/history/encounters.php` (`BillingUtilities::getBillingByEncounter` +
`InvoiceSummary::arGetInvoiceSummary` + `drug_sales` for pharmacy lines); no edit links, no "+ Add",
no fee-sheet access. pid/encounter come from GET or fall back to the session (works when opened from
the menu). Read gate: `encounters/coding|notes` or `acct/rep|bill`. `Bootstrap::filterEncounterMenu`
now: **billers** (`acct/bill`, e.g. cashier) keep the editable **Fee Sheet**; **non-billers** (the
doctor) get a **"Patient Bill"** menu entry (an `href` child, no `directory`) opening the read-only
page in an encounter sub-tab via `openNewForm`. The page lives in the bind-mounted module dir (no
sites-volume installer needed). Verified via Selenium: doctor1 Administrative = {Patient Bill} (no
Fee Sheet); the bill renders the encounter's HCPCS REG + CONS-GP charges with a Total and a
"View only" badge and **zero** inputs/buttons; session-only path (no GET params) also resolves the
current encounter; cashier is not given the read-only replacement.

Vitals form customization (OPD): Trimmed the core Vitals encounter form
(`interface/forms/vitals/`) to show only clinically relevant rows — Weight, Height/Length, BP
Systolic, BP Diastolic, Pulse, Respiration, Temperature, Oxygen Saturation, BMI (read-only,
auto-calculated), BMI Status, and Clinical Notes — by setting `'hide' => true` on the unwanted
entries in the `$vitalFields` array of `C_FormVitals.class.php` (Temperature Method, Oxygen Flow
Rate, Inhaled O2 Concentration, Head/Waist Circumference, the 3 pediatric percentile rows, and
Growth Chart actions). Made `vitals_textbox_conversion.html.twig` honor `field.hide` (it previously
ignored it, leaking the circumference rows) and gated template-type rows on `not field.hide` in
`vitals.html.twig`. Added automatic abnormal flagging: `vitals.js` now defines age-aware reference
ranges (infant/toddler/preschool/school/adolescent/adult bands for pulse, respiration, BP; fixed
ranges for temperature in °F and SpO2; BMI 18.5–24.9 for adults only) and an `evaluateVital()`
function that auto-selects the Abn interpretation (N/H/L) and color-codes the cell green (normal)
or red (abnormal) as the user types and on page load. Patient age is passed from the template into
`vitalsForm.init()`. The BMI row gained an interpretation selector (`interpretation[BMI]`) so it can
be flagged/persisted; auto-set Abn values save through the existing generic interpretation loop into
`form_vital_details` with no PHP save change. Color classes added to `vitals.css`. PHP and JS lint
clean; no Twig cache to clear (disabled in dev). Browser verification of the running form still
pending.

Vitals metric-only display: Set the `units_of_measurement` global from 1 (both, US main) to 4
(metric only) in the `globals` table — the proper OpenEMR mechanism (Admin → Config → "Units for
Visit Forms"). The conversion template now hides the lbs/in/F rows and shows only kg (Weight), cm
(Height/Length), and °C (Temperature). Fixed a metric-only quirk in `vitals.js`: that display mode
makes the interpretation ("Abn") dropdown render twice with the same id (hidden US row + visible
metric row), so `evaluateVital()` now selects the *visible* selector (skipping rows with class
"hide") for both the color toggle and the persisted value. Values are still stored internally in US
units, so the °F-based temperature range still applies correctly. Live immediately (globals load
per request); no restart needed.

## Radiology & procedure orders — 2026-05-31 (APPLIED)

Two new orderable channels prepared alongside the existing "Clinic Lab Tests", following the seed-lab-procedures / seed-service-codes pattern. `scripts/seed-radiology-procedure-codes.sql` seeds 9 radiology codes (`RAD-*`, superbill 'Radiology') and 8 new procedure codes (`PROC-SUTURE/ID/CIRC/FBAIR/FBENT/NEB/CATH/POP`, superbill 'Procedure') into `codes` (code_type=3/HCPCS) with matching `prices` at `pr_level='standard'` (TZS, 0-dec; **prices are DRAFTS pending clinic review**). `scripts/seed-radiology-procedures.sql` seeds two `procedure_providers` — Clinic Radiology (ppid=2) and Clinic Procedures (ppid=3) — and two `grp` parents: "Imaging & Radiology" (id 10, procedure_type_name='imaging', 9 `ord` leaves) and "Clinic Procedures" (id 20, procedure_type_name='procedure', 10 `ord` leaves); the Procedures group also re-exposes the pre-existing PROC-DRESS/PROC-INJ as orderable. Both scripts idempotent with distinct markers (`OPD-RAD-SEED`, `OPD-PROC-SEED`) so re-runs don't touch the lab group (`OPD-SEED`). Pricing reaches billing through the Fee Sheet (native pattern): the doctor/cashier adds the matching service code on the encounter Fee Sheet and the TZS price auto-fills from `prices` — OpenEMR does not post charges directly from a procedure order.

**STATUS: applied and verified** (user ran both scripts on 2026-05-31 via `docker compose cp` of the scripts to `mysql:/tmp/{rc,rp}.sql` then `docker compose exec mysql mariadb -u root -p openemr -e "source ..."` — one `source` per invocation). DB confirms providers Clinic Radiology (ppid=2) and Clinic Procedures (ppid=3); group "Imaging & Radiology" (id 10) with 9 `ord` leaves and "Clinic Procedures" (id 20) with 10 `ord` leaves; all 19 priced; lab group untouched. Pending: clinic to confirm/adjust the DRAFT TZS prices; optional UI walkthrough via /verify (Configure Orders and Results tree, Procedure Order picker, Fee Sheet auto-price) with TEST Mwajuma Hassan.

Vitals read-only color flags: The green/red flagging previously only colored the "Abn" dropdowns
on the edit form; the read-only "Vitals History" table (`vitals_historical_values_complete.html.twig`)
showed plain values with no color and still listed hidden rows (e.g. "Temp Location"). Fixed:
(1) gated template-type rows on `not field.hide` in the history table too, so Temp Method / Growth
Chart no longer appear (BMI Status and Other Notes remain, as chosen). (2) `vitals_historical_values.html.twig`
now colors each value cell from the saved per-column interpretation in `form_vital_details`
(`result.get_details_for_column(input).get_interpretation_option_id()`): `N` → green
(`vital-normal`), any other non-empty code (H/L/A/…) → red (`vital-abnormal`); the column name is now
passed in via `input` from the textbox/conversion/BMI row includes. This reflects the actual stored
interpretation (including any clinician override), so it is correct regardless of display units.
NOTE: records saved before auto-flagging existed have no stored interpretation and so show no color
until re-saved. All edited templates pass a Twig parse check.

## OPD re-architecture — Phase 9: simplified ordering, departmental resulting & per-order billing gate  ✅  2026-05-31
Three changes so doctors order simply, the right personnel post results, and only after the order's
bill is cleared. (Supersedes the "OpenEMR does not post charges directly from a procedure order"
note above — orders now auto-bill on placement.)

- **Simplified Procedure Order form (doctor).** Role-scoped, upgrade-tracked core edit to
  `interface/forms/procedure_order/common.php`: for non-admin a JS block hides every option row
  except the **Department** selector (the `form_lab_id` provider picker, relabelled), the procedure
  picker + **Add**, and one **Order note** (`form_clinical_hx`); the one button is relabelled **Send
  request** (save-and-exit; Transmit/eReq hidden). Admin/super keep the full native form. The picker
  already filters the catalogue by the selected department, so picking *Radiology* shows the X-rays,
  *Procedures* shows Wound Dressing, etc. — which was the original "radiology doesn't show" confusion.
- **Auto-bill on placement.** New `oe-module-opd/src/Service/OpdOrderBillingService.php`
  `syncOrderCharges()` (hooked one line after `saveProcedureOrderCodes` in common.php, in try/catch so
  it never blocks saving) adds the ordered service code(s) to the encounter Fee Sheet via
  `BillingUtilities::addBilling` (price from `prices`@standard) — mirroring `OpdVisitService::addCharge`.
  Idempotent per code; skips codes not present as HCPCS service codes (no junk lines). This also fixes
  the prior gap where lab orders created no charge.
- **Departmental result routing.** New dedicated **Radiologists** ACL role (`scripts/seed-radiology-acl.php`,
  group id 19, grants `patients/sign`) + user **radiology1** (`scripts/seed-radiology-user.sql`, id 11,
  base Clinicians; password = `SECRETS.txt` line **[10]**). Doctors already hold `patients/sign`+`patients/lab`
  (Physicians) so **minor-theatre procedure notes need no new grant**. Role→department map in the service:
  Lab Technicians→Clinic Laboratory (ppid 1), Radiologists→Clinic Radiology (2), Physicians→Clinic
  Procedures (3); admin→all. One role-derived worklist `oe-module-opd/public/results_queue.php` lists a
  user's department orders with a **Paid / Awaiting payment** badge (per-order) and enables *Enter results*
  only when paid; opens native `orders_results.php?set_pid=` (which now honours `set_pid` in entry mode too).
  Menus: lab "Results Worklist" + new `opd_radiology.json`/`opd_radiology_patient.json` + doctor "Procedure
  Worklist", installed via `scripts/install-role-menus.sh`; `radiology1` wired in `scripts/seed-role-menus.sql`.
- **Hard gate (non-bypassable).** Role-scoped core edit to `interface/orders/orders_results.php` POST
  handler: for non-admin, every order being saved must pass `canResultOrder` (department) **and**
  `isOrderPaid`, else the save dies with a clear message. `isOrderPaid` derives per-order "paid" by a
  deterministic **waterfall** over `billing` lines (id order) vs the encounter's total cleared
  (`ar_activity` pay+adj) — necessary because OPD payments are posted **lump/unallocated** (`PCP`,
  code='') by the cashier, so there is no per-code allocation. **Assumptions (documented in the service
  header):** payments apply to charges in posting order; identical service code on two orders in one
  encounter is matched by code; waterfall is over billing only (not pharmacy `drug_sales`).
- **Verified at the service layer** (probe on TEST Mwajuma Hassan, fully cleaned up — 0 residue):
  allowedDepartments lab1=[1]/radiology1=[2]/doctor1=[3]/admin=ALL; a synthetic RAD-CXR order auto-billed
  15,000; before payment isOrderPaid=unpaid and canResultOrder ALLOW only for radiology1/admin (lab1 &
  doctor1 deny); after a 15,000 Cash payment balance→0 and isOrderPaid=PAID. All edited PHP lints clean,
  menu JSON valid, no fatals in container logs (only pre-existing native `order_abn`/specimen-row notices).
- **Caveats / pending:** DRAFT TZS prices still need clinic confirmation; the native result screen lists
  all of a patient's orders (the worklist + save guard enforce department/payment, but display isn't
  dept-filtered); browser **/verify** click-through (doctor sends order → cashier collects → radiology1
  enters results; isolation lab1↔radiology) still to be run by the user.
- **Reproduce-after-reset (append to the Phase-8 chain):** … → `seed-radiology-procedure-codes.sql` →
  `seed-radiology-procedures.sql` → `seed-radiology-user.sql` → `php scripts/seed-radiology-acl.php` →
  `seed-role-menus.sql` → `./scripts/install-role-menus.sh`. New tracked core edits (reapply after an
  OpenEMR upgrade): `interface/forms/procedure_order/common.php` (trim + auto-bill hook),
  `interface/orders/orders_results.php` (set_pid + result gate).

## OPD re-architecture — Phase 9.1: department-picker Orders menu + per-line order note  ✅  2026-05-31
Follow-up simplifications after the doctor used Phase 9 (no DB migration, no save-function change):
- **Orders menu lists departments.** `oe-module-opd/src/Bootstrap.php::filterEncounterMenu` now replaces the
  single "Procedure Order" item under **Orders** with one `href` child per active `procedure_providers` row —
  **Clinic Laboratory / Clinic Procedures / Clinic Radiology** — each opening
  `load_form.php?formname=procedure_order&lab_id=N` in an encounter sub-tab (same href pattern as "Patient
  Bill"). Admin/super keep the plain native item (function returns early for them). Verified by constructing an
  `EncounterMenuEvent` as `doctor1`: the 3 departments render with the correct `lab_id` (1/3/2).
- **Form pre-scoped to the department.** `common.php` seeds `$row['lab_id']` from `$_GET['lab_id']` for a new
  order (defaults block); the role-scoped trim JS now hides the **entire Department/Provider/Date row** (the
  department is chosen from the menu and shown in the "Procedure Order Details — <dept>" heading). The hidden
  `form_lab_id` select keeps its preset, so the procedure picker still filters to that department.
- **Per-line "Order note" replaces "Diagnosis Codes".** In `common.php` the per-procedure diagnosis cell (both
  the hidden `<template>` and the rendered row) is now a free-text **textarea** keeping the **same field name**
  `form_proc_type_diag[]`, so `saveProcedureOrderCodes` writes it to `procedure_order_code.diagnoses` unchanged
  — and that column already renders in the order/results view (`single_order_results.inc.php`), so the note
  surfaces to the resulting personnel with **no save/schema/display edits**. The `diagnoses` column is
  intentionally repurposed as the per-line order note (in-house pilot: no HL7 transmit, no diagnosis-based
  billing). Header relabelled "Diagnosis Codes" → "Order note"; the now-redundant order-level note
  (`form_clinical_hx`) row is hidden by the trim JS. The removed diagnosis-picker classes are bound via
  `nullableFunction()` (null-safe), so nothing breaks.
- Both edited files lint clean; container logs show no new fatals (only the pre-existing native
  `order_abn`/specimen-row notices). Browser **/verify** click-through (Orders→Radiology opens scoped form;
  two procedures each with their own note; note shows to radiology1 after payment) still for the user.
- **Tracked core edits unchanged in count:** `interface/forms/procedure_order/common.php` (now also: lab_id
  preset, Department-row hide, diagnosis→note cells) and `oe-module-opd/src/Bootstrap.php` (Orders→departments).

## OPD re-architecture — Phase 10.1: fast multi-row prescription grid  ✅  2026-05-31
The native one-drug-per-page Rx form (RxNorm/RxCUI, e-prescription, formulary) was unusable at a queue of 20.
Replaced it for the OPD doctor with a single grid — **each drug is one row**: Drug · Dose · Unit · Frequency · Days →
**Quantity auto-calculated** (dose × per-day × days), multiple rows. All in `oe-module-opd`, no core edits.
- **Quantity math:** frequencies **OD/BD/TDS/QDS/NOCTE/PRN/STAT** with per-day 1/2/3/4/1/—/1. e.g. 2 tabs × BD(2) ×
  3 days = **12**. PRN has no multiplier → quantity entered manually. Computed **server-side** in `OpdPrescribeService::save`
  (not trusting the JS) and also live in the browser as the doctor types.
- **Writes standard `prescriptions` rows** via the native `library/classes/Prescription.class.php` (`persist()` — fills
  uuid/txDate/usage_category/request_intent so no raw-SQL column pitfalls): `drug_id`, name, `dosage`=dose, `form`=unit
  (`drug_form` option), `interval`=mapped freq (`drug_interval`), `quantity`=computed, `size`, a human `note`
  ("2 tablet BD × 3 days"), `active=1`, tied to the encounter. So the **Phase-10 pharmacy chain is unchanged** — the
  script appears on `pharm1`'s worklist; stock is deducted at **dispense** (after payment), never at prescribe.
- **Screen `public/prescribe.php`** (ACL `patients/rx`): grid with a shared `<datalist>` of all 596 drugs (JS maps the
  typed name → `drug_id` + default Unit from the drug's form), per-row Quantity auto-fill, **+ Add drug** / **Save**, and
  a list of this visit's prescriptions with **Remove** (archive; blocked once the script is already at the pharmacy).
  Service: `OpdPrescribeService` (FREQUENCIES, `drugCatalogue`, `unitOptions`, `listForEncounter`, `save`, `archive`).
- **Wiring:** the doctor's **Prescriptions** — both the encounter **Clinical** dropdown (`Bootstrap::filterEncounterMenu`,
  href with the session pid+encounter) and the patient menu — now open `prescribe.php` instead of the native controller.
  Admin keeps the native Rx form.
- **Verified** (service probe on TEST Mwajuma, drug 259, 0 residue): `save()` dose 2 / BD / 3 days → `prescriptions`
  qty **12**, interval b.i.d., note "2 tablet BD × 3 days"; a PRN row → manual qty 5, interval p.r.n.; both show in
  `OpdPharmacyService::getQueue()` as **to_confirm**; archive removes the un-confirmed one. Encounter Clinical menu probe
  confirms the href → `prescribe.php?pid=&encounter=`. All PHP lints clean, menu JSON valid, no fatals.
- Browser **/verify** (doctor adds 2 rows, watches Quantity auto-fill, Save → both on `pharm1`'s worklist) remains for the user.

## OPD Visit Note — persistence/summary bug fixed (form_name NULL + duplicate instances)  ✅  2026-05-31
Doctor reported: OPD Visit Note saved but reopened blank, and didn't show on the encounter summary. The data was
actually saving (e.g. enc 31 `form_id 6` held all 9 fields) — two real bugs:
- **Root cause of the blank summary:** `interface/forms/LBF/new.php` sets `forms.form_name` from the LBF's **top-level
  group** (`layout_group_properties` where `grp_group_id=''`). `scripts/seed-lbf-opd-visit.sql` created groups 1–5 but
  **omitted that top-level row**, so every OPD Visit Note saved with `form_name = NULL` → the encounter summary heading
  (`forms.php` → `xl_form_title($form_name)`) rendered blank and the note looked absent. **Fix:** added the
  `('LBFopd_visit','', 'OPD Visit Note','Core',0,1,1)` row to the seed (idempotent), re-ran it, and backfilled
  `UPDATE forms SET form_name='OPD Visit Note' WHERE formdir='LBFopd_visit' AND form_name IS NULL` (5 rows). The note's
  field data (lbf_data) always rendered via `LBF/report.php`; only the title was missing.
- **Root cause of "reopened blank":** the encounter "Add form" menu always creates a **new blank LBF instance**, so
  clicking "OPD Visit Note" again opened an empty one while the saved note sat in a different instance (enc 31 had a blank
  `form_id 5` + the real `form_id 6`). **Fix:** new opener `oe-module-opd/public/opd_visit_note.php` redirects to the
  **existing** note (the instance with the most `lbf_data`, via `view_form.php?id=`) or creates one only if none exists;
  `Bootstrap::filterEncounterMenu` now routes the Clinical **OPD Visit Note** item through it (href, bind-mounted → live).
  Cleaned up existing blank duplicates (`UPDATE forms SET deleted=1 … form_id NOT IN (SELECT form_id FROM lbf_data)` — 1 row).
  Also de-duplicated older multi-instance encounters (a one-time merge: for each encounter with >1 active note, copy any
  field the secondary holds that the primary lacks into the richest instance, then archive the secondary — lossless).
  Result: every encounter now has exactly **one** active OPD Visit Note (enc 12→form 1, enc 16→form 3, enc 31→form 6).
- **Verified:** top-level `''` group present; all LBFopd_visit forms now named "OPD Visit Note"; enc 31 keeps a single
  active note (`form_id 6`, 9 fields); the Clinical menu opener resolves enc 31 → `form_id 6` (not a new blank). PHP lints
  clean, no fatals. The LBF plugin (Select2/accordion/provider-lock) was not at fault — saving worked throughout.

## OPD re-architecture — Phase 10.2: one order per department, summary order, triage vitals  ✅  2026-05-31
Three encounter refinements, extending the same opener pattern as the OPD Visit Note. All in `oe-module-opd` except one
tracked core edit (the summary ORDER BY).
- **One procedure order per department per encounter.** The Orders menu created a new `procedure_order` per click (a visit
  had 3 separate "Clinic Laboratory" orders). New opener `public/opd_order.php?lab_id=&encounter=` reopens the encounter's
  existing active order for that department (`view_form.php?formname=procedure_order&id=`) so the doctor **adds** procedures
  to it, or creates a new one only if none exists; `Bootstrap::filterEncounterMenu` points the per-department Orders items at
  it. **One-time lossless merge** of existing duplicates: per `(encounter, lab_id)` with >1 active order, moved
  `procedure_order_code` (+ `procedure_specimen`/`answers`/`report` re-keyed by `(order,seq)`) into the primary with fresh
  seqs, archived secondaries (`procedure_order.activity=0` + `forms.deleted=1`), re-ran `syncOrderCharges` (idempotent per
  code → no dup billing). Result: enc 31 lab order (id 2) now holds all 6 tests (seq 1–6); every (encounter,dept) has exactly
  one order. `results_queue.php` now filters `po.activity=1` so archived orders never surface.
- **Encounter summary order = Vitals → OPD Visit Note → Orders → … → Medications.** Tracked core edit
  `interface/patient_file/encounter/forms.php:876` ORDER BY → `FIELD(formdir,'procedure_order','LBFopd_visit','vitals') DESC,
  form_name, date DESC` (reversed list so DESC yields vitals→note→orders→rest; the newpatient "Visit Summary" block sorts last,
  carrying the **native** Dispensed Medications table = medications last). Verified order for enc 31. **De-duplicated meds:**
  removed the module's `renderDispensedMeds` listener (it duplicated the native newpatient table) so meds now show **once**,
  via the nicer native table, positioned last.
- **Triage vitals (one per encounter, one click).** Vitals already attach to the registration-created encounter; added polish:
  new `public/opd_vitals.php?pid=&encounter=` sets the patient/encounter session (so it works from the triage queue) and
  reopens the encounter's existing `vitals` form (or new). The encounter **Clinical → Vitals** item routes through it
  (one vitals per visit, nurse and doctor), and the triage queue gained a **"Record vitals"** button that lands the nurse
  straight on the Vitals form (kept "Open chart" as a secondary). 
- **Verified:** menu probe (doctor1, enc 31) shows Clinical→{Vitals,OPD Visit Note,Prescriptions} and Orders→{Lab,Procedures,
  Radiology} all routed to openers; `opd_order.php` lab_id=1 → existing order 2, `opd_vitals.php` → existing vitals form 1;
  summary order correct; all PHP lints clean; no fatals. (Bind-mounted module + forms.php → live; no menu reinstall needed.)

## OPD re-architecture — Phase 9.2: per-line cashier collection + exact per-order gating  ✅  2026-05-31
The cashier collected one lump payment per encounter (unallocated `ar_activity`, `code=''`), so the record-payment
screen showed only a summed total. Now the cashier sees **each charge with its own fee and collects per line**, and
paying a specific order **unlocks exactly that order's department** to post results. All changes are in `oe-module-opd`
(no core edits).
- **Per-code payment:** `OpdGatingService::recordLinePayment($pid,$enc,$allocations,$method)` posts one `ar_activity`
  row per charge line (`code_type`/`code`/`modifier`/`pay_amount`, `account_code='PCP'`) under one `ar_session` + one
  `payments` receipt. The old lump `recordPayment` is kept for back-compat but is no longer the collection path.
- **Exact gating:** `OpdOrderBillingService::isOrderPaid` now reads the native per-code A/R summary
  (`InvoiceSummary::arGetInvoiceSummary`) — an order is paid iff every one of its codes is billed and has zero balance.
  The Phase-9 waterfall heuristic is retired (it only existed because payments were unallocated). Residual caveat: the
  same service code on two orders in one encounter is aggregated by code; a legacy lump payment (code='') no longer
  clears an order (all current collection is per-line).
- **Cashier per-line screen:** new `oe-module-opd/public/cashier_collect.php?pid=&encounter=` reuses the
  `encounter_bill_view.php` assembly (`getBillingByEncounter` + `arGetInvoiceSummary` + `drug_sales`) to show each line
  with Charge/Paid/Balance, a checkbox + editable amount per unpaid line, a method selector, and **Collect selected**
  (posts `recordLinePayment` then `activateIfPaid`; never overpays a line). Generic over any billing/`PROD` line, so the
  deferred medication flow reuses it.
- **Wiring:** `cashier_checkout.php` worklist broadened from RG/PD-only to **active visits with an outstanding balance**
  (so charges added after triage are collectible), RG auto-advance kept, inline lump-collect replaced by an
  **"Open / Collect"** link to `cashier_collect.php`. Cashier patient-menu **"Take Payment"**
  (`menus/patient_menus/opd_cashier_patient.json`) repointed from native `front_payment.php` to `cashier_collect.php?pid=`
  (re-installed via `install-role-menus.sh`).
- **Verified** (service probe on TEST Mwajuma, 0 residue): two orders RAD-CXR 15,000 + PROC-DRESS 5,000 (visit bal 20,000);
  collecting **only** the radiology line → RAD bal 0 / PROC bal 5,000, `isOrderPaid(rad)=PAID`, `isOrderPaid(proc)=unpaid`,
  visit bal 5,000; then collecting the procedure line → both PAID, visit bal 0. All edited PHP lints clean; cashier menu
  JSON valid; no fatals in logs.
- **Medications — deferred (next phase, decided):** doctor prescribes via the **native Prescriptions form**; the
  pharmacist **confirms dispensable** before the cashier bills; payment unlocks dispensing. The per-line cashier screen
  already handles `drug_sales` (`PROD:<id>`) lines, so that phase adds the prescribe→confirm stage, not new cashier UI.

## OPD re-architecture — Phase 10: pharmacy confirm → bill → pay → dispense  ✅  2026-05-31
Medication leg, mirroring the order leg: the doctor prescribes, the **pharmacist confirms dispensable BEFORE the cashier
bills**, the cashier collects per item (reusing the Phase-9.2 per-line screen), and dispensing is **hard-gated on payment**.
All in `oe-module-opd` (no core edits). Decided earlier: Rx source = native Prescriptions form.
- **Lifecycle on one `drug_sales` row** (no schema change): `inventory_id = 0` = pending charge (confirmed, not handed
  over); `inventory_id > 0` = dispensed from that lot. New `OpdPharmacyService`:
  - `confirmPrescription(rxId, fee)` — idempotent; inserts a pending `drug_sales` row (mirrors `DrugSalesService`'s
    non-dispensable insert incl. `UuidRegistry`), **no stock deducted**. It appears on the cashier bill as `PROD:<drug_id>`.
  - `isMedPaid(saleId)` — per-code A/R exact (`PROD:<drug_id>` balance ≤ 0), same source as orders.
  - `dispense(saleId)` — refuses unless paid; picks a **FEFO** lot with enough on_hand, decrements it, stamps the sale
    `inventory_id`+`billed=1` (pending → dispensed) inside a transaction.
  - `getQueue()` — active-visit prescriptions with computed status: to_confirm / awaiting_payment / ready / dispensed /
    manual (free-text drug, no catalogue link).
- **Pharmacist screen:** new `public/pharmacy_queue.php` (ACL `admin/drugs`) — per prescription shows in-stock qty + a fee
  box (pre-filled from `prices` when present — only **35/596** drugs are priced, so the pharmacist sets the fee), a
  **Confirm & bill** button (disabled if stock < qty), and a **Dispense** button that appears only once paid.
- **Wiring:** pharmacy main menu gains **Pharmacy Worklist**; the doctor's patient menu **and the encounter "Clinical"
  dropdown** gain **Prescriptions** (native `controller.php?prescription&list`, gated `patients/rx`; the encounter item is
  appended in `Bootstrap::filterEncounterMenu` as an href next to Vitals/OPD Visit Note — a new Rx ties to the open
  encounter via `$_SESSION['encounter']`). `OpdEncounterService::getDispensedMeds` now
  filters `inventory_id > 0` so a pending charge doesn't show as "dispensed" on the encounter summary. Menus re-installed.
- **Shared fix:** a single warning-muting wrapper `OpdGatingService::invoiceSummary($pid,$enc)` now fronts every
  `arGetInvoiceSummary` call (orders + meds + cashier bill). Native `InvoiceSummary` (`src/Billing/InvoiceSummary.php:107-111`)
  does `+=` on an uninitialised `PROD:` key, emitting harmless PHP-8 "undefined array key" notices for any product sale; the
  wrapper mutes only E_WARNING/E_NOTICE around that native call (figures unchanged). Not a core edit.
- **Verified** (service probe on TEST Mwajuma, drug 259 Ibuprofen, 0 residue, stock restored): prescribe qty 10 → confirm
  (fee 5,000, stock stays 500) → **dispense blocked while unpaid** (stock untouched) → cashier collects `PROD:259` →
  `isMedPaid=PAID` → dispense → `inventory_id` set, `billed=1`, **stock 500→490**. The order per-line probe still passes
  (per-code gating unchanged); all edited PHP lints clean; pharmacy/doctor menu JSON valid; no fatals, no InvoiceSummary
  notices after the wrapper.
- **Caveats:** two prescriptions for the same drug in one encounter share that code's paid state (A/R aggregates by code);
  dispense needs a single lot with enough stock (no split-lot); free-text prescriptions (no `drug_id`) are flagged "manual".
  Browser **/verify** click-through (doctor prescribes → pharm1 confirms → cashier collects the med line → pharm1 dispenses,
  stock drops) is the remaining sign-off.

---

### smartEMR end-user guide rebuilt — annotated v2.0 ✅ 2026-05-31

The end-user guide was **recreated from scratch as v2.0** because v1.0 documented the *native* OpenEMR
screens (Fee Sheet, Calendar appointments, native Prescriptions, `pending_orders.php`,
`drug_inventory.php`), whereas staff now use the **custom `oe-module-opd` worklist screens**, a new
**Radiologist** role, per-line payment gating, and the pharmacy confirm→bill→dispense lifecycle. Every
screenshot now carries **numbered orange badges** pointing at the exact button/field, each backed by a
numbered legend in the document.

- **New screenshot annotation engine** (`scripts/capture-guide-screenshots.py`): a Selenium-injected DOM
  overlay (`ANNOTATE_JS` + `shot_annotated`/`grab` with `targets=[{selector,n,label,position}]`) draws an
  orange ring + numbered badge on each element via `getBoundingClientRect`; missing/off-viewport targets are
  reported and skipped so a run never aborts. Re-targeted to the real custom screens and the new Radiology role.
- **New scenario driver** (`scripts/guide-scenario.php`, CLI via `docker compose exec openemr php …`): drives
  the TEST patient (pid=2 only) through today's workflow using the real OPD service layer
  (`--stage=reset|register|pay-consult|triage|consult|order-lab|order-rad|pay-orders|prescribe|pharm-confirm|pay-meds|dispense|discharge|vars|status`),
  so each worklist renders in the exact state to photograph. `reset` clears only today's pid=2 rows (repeatable).
  The capture orchestrator interleaves stage-advance and screenshot so gated buttons (Dispense, paid-only
  "Enter results") actually appear.
- **20 annotated screenshots** in `docs/guide-screenshots/` (01 login … 20 flow board): login, dashboard,
  reception (search + allocate), cashier (worklist + per-line collect + orders round), nurse (triage + vitals),
  doctor (queue + OPD note + lab/radiology order + prescribe grid), lab results, radiology results, pharmacy
  (confirm/dispense + inventory), Patient Flow Board.
- **Rebuilt document** (`scripts/build-user-guide.js`): adds `shotL(file,caption,legend)` (figure + numbered
  legend table mirroring the on-image orange badges), reads true PNG dimensions for aspect-correct images, adds
  the **Radiology station** and an **Administrator Setup appendix** (ICD-10 import, clinic details, prices,
  opening stock, users, backups), keeps the Swahili hints / tip / status-code tables, and bumps the cover to
  **Version 2.0**. Output: `smartEMR_User_Guide.docx` (~1.5 MB, 18 embedded figures).
- **Verified:** docx is valid OOXML; 18 referenced figures = 18 embedded; **no patient names or password values
  in the document text** (only the screenshot images show the TEST patient). `selenium` was pip-installed on the
  host for the capture script.
- **Notes / residue:** the capture wrote a normal test visit for **pid=2 only** (consultation + lab + radiology
  paid, two prescriptions, one med confirmed+paid "ready to dispense", not yet dispensed — so no stock was
  decremented this run); re-run `--stage=reset` to clear it. The lab/pharmacy figures also show two pre-existing
  test patients (Lucy Mushi, Isaya Tarimo) already in the queues, which usefully illustrate the paid/unpaid gating.
