# OPD end-to-end smoke test — findings sheet

**Purpose:** walk one patient through Reception → Cashier → **Nurse (triage)** → Doctor →
Lab → Pharmacy → Discharge, logging in as each role to see how the stations connect. Note
any change needed in the **"Notes / change needed"** line under each step.

**App:** http://localhost:8300  **Date tested:** ____________  **Tester:** ____________

**Test patient (use this dummy, never a real name):** `TEST Mwajuma Hassan`

### Logins (passwords in `SECRETS.txt`, do not write them here)

| Role | Username | Password line |
|---|---|---|
| Reception | `reception1` | [4] |
| Cashier | `cashier1` | [5] |
| Doctor | `doctor1` | [6] |
| Lab | `lab1` | [7] |
| Pharmacy | `pharm1` | [8] |
| Nurse | `nurse1` | [9] |

Log out between stations (top-right menu → Logout) and log in as the next role, so you
see exactly what each role can and cannot see.

### Appointment status legend (the cross-station signal — Flow Board)

`RG` Registered/awaiting cashier · `PD` Paid/awaiting **triage** · `TR` Triaged/awaiting
doctor · `WD` With doctor · `WL` Awaiting lab · `LB` Lab complete · `WP` Awaiting pharmacy ·
`CM` Completed/Discharged.
The **Patient Flow Board** (top menu **Patient → Patient Flow Board**, or the **Tracker**)
shows everyone with today's appointment colour-coded by status. Each station updates the
status to signal the next station. The **encounter** (today's visit) is the shared record
every station reads/writes.

### Pre-drive result (automated HTTP/app-layer check, 2026-05-30) — ✅ PASS

Before you run the manual click-through, the plumbing was verified through the real app
stack (real login, session, and ACL enforcement — not pixel clicks):
- All 5 role users log in; the app shell/menu builds for each.
- Each role reaches its station screens (reception register + flow board; cashier
  billing + flow board; doctor flow board; lab queue + **results entry**; pharmacy inventory).
- ACL isolation confirmed: only `lab1` can open the sign-gated results page;
  `pharm1`/`nurse1`/`reception1` are correctly **Not Authorized** there, and `reception1`
  is denied inventory.
- The doctor's lab-order search returns the seeded tests (mRDT, Blood Smear, CBC, RBS…).

Not auto-tested (do these by hand): the multi-step data entry itself — form fills, fee
sheet totals, payment, result values, and the actual inventory decrement on dispense.

### Pre-flight (already done — for reference)

- Demographics form trimmed to ~18 fields (`scripts/seed-demographics-layout.sql`).
- In-house lab + 8 orderable tests seeded (`scripts/seed-lab-procedures.sql`).
- Dedicated **Lab Technicians** ACL role grants `lab1` result-signing only — `nurse1`/`pharm1`
  do NOT get it (`scripts/seed-lab-acl.php`).
- All 5 role users confirmed able to log in.

---

## 1 · Reception — register + book the visit  (login `reception1`)

**Navigate:** top menu **Patient → New/Search**.
**Do:**
- [ ] Register `TEST Mwajuma Hassan` — sex Female, a DOB, a mobile phone. Save.
- [ ] Confirm the form is **short**: name, DOB, sex, **Payment Type** (required — Cash/NHIF/
      CHF/…), Reg No., marital status, address/city/region, mobile, emergency contact,
      provider, referral source, guardian. **No Insurance section**, no Employer, no SSN.
- [ ] On Save, a **registration card** popup should appear and auto-print — clinic name,
      patient name, **Registration No.** and a barcode. Keep/print it; the Registration No.
      is what other stations type into **Patient → search** to pull the patient.
- [ ] Create today's appointment: top menu **Calendar** → click today's slot → patient =
      Mwajuma, **Provider = Doctor One**, reason "Fever", **Status = Registered (RG)**. Save.
- [ ] Open **Patient → Patient Flow Board** — confirm Mwajuma shows as **RG**.

**Expected:** patient saved; card prints; encounter auto-created (RG is a check-in status);
appointment visible on the Flow Board as RG.
**Result:** ⬜ pass ⬜ fail
**Notes / change needed:** _______________________________________________

---

## 2 · Cashier — charge the consult + take payment  (login `cashier1`)

**Navigate:** **Patient → Patient Flow Board** → click Mwajuma → open her chart → today's
encounter. (Encounter auto-creates on check-in; if absent, **Patient → New Encounter**.)
**Do:**
- [ ] In the encounter, add **Fee Sheet** → search service **CONS-GP** (General Consultation)
      → add it. Confirm the fee shows in **TSh** (no decimals). Save.
- [ ] **Checkout / Take Payment:** open the encounter's Checkout → method **M-Pesa** →
      amount = total → apply payment. (Receipt optional.)
- [ ] Set appointment status to **Paid (PD)** on the Flow Board (now = awaiting triage).

**Expected:** charge recorded; payment recorded; status → PD so the nurse triages her next.
**Result:** ⬜ pass ⬜ fail
**Notes / change needed:** _______________________________________________

---

## 3 · Nurse — triage / vitals, route to a doctor  (login `nurse1`)

**Navigate:** **Patient → Patient Flow Board** → click Mwajuma (PD) → open today's encounter.
**Do:**
- [ ] Add the **Vitals** form → record temp, BP, weight, pulse. Save.
- [ ] **Route to a doctor:** open the appointment (Calendar event) and set **Provider =
      Doctor One** (this is how the patient reaches that doctor's queue).
- [ ] Set status **Triaged (TR)**.
- [ ] Confirm you **cannot** reach pharmacy dispensing (Inventory) — nurse no longer dispenses.

**Expected:** vitals saved; provider set; status → TR; nurse is **Not Authorized** for the
dispensary.
**Result:** ⬜ pass ⬜ fail
**Notes / change needed:** _______________________________________________

---

## 4 · Doctor — see triage queue, write the OPD note  (login `doctor1`)

**Navigate:** **Patient → Patient Flow Board** — it defaults to **your** queue (patients routed
to you). Confirm Mwajuma (TR) is in your list, then open today's encounter.
**Do:**
- [ ] Set status **With Doctor (WD)**.
- [ ] Add form **OPD Visit Note**. Fill chief complaint, HPI, exam.
- [ ] In **Diagnosis (ICD-10)**, search malaria → pick e.g. **B54** (unspecified malaria). Save.

**Expected:** OPD note saves; ICD-10 search returns codes.
**Result:** ⬜ pass ⬜ fail
**Notes / change needed:** _______________________________________________

---

## 5 · Doctor — order a lab test  (still `doctor1`)

**Navigate:** in the same encounter, add form **Procedure Order**.
**Do:**
- [ ] Lab/provider = **Clinic Laboratory** → search & add **Malaria Rapid Diagnostic Test**
      (mRDT). Save the order.
- [ ] Set status **Awaiting Lab (WL)**.

**Expected:** order created (status pending) and addressed to Clinic Laboratory.
**Result:** ⬜ pass ⬜ fail
**Notes / change needed:** _______________________________________________

---

## 6 · Doctor — prescribe medication  (still `doctor1`)

**Navigate:** **Patient → Prescriptions** (or the Rx panel on the patient dashboard).
**Do:**
- [ ] Prescribe **Artemether-Lumefantrine 20/120mg** (in stock), with dose/quantity. Save.

**Expected:** prescription saved and linked to the patient/encounter.
**Result:** ⬜ pass ⬜ fail
**Notes / change needed:** _______________________________________________

---

## 7 · Lab — run the test, enter the result  (login `lab1`)

**Navigate:** top menu **Procedures → Pending Review** (lab queue).
**Do:**
- [ ] Find Mwajuma's mRDT order → enter result (e.g. **Positive**) → save / sign.
- [ ] Set status **Lab Complete (LB)**.

**Expected:** `lab1` can open the results screen (dedicated Lab role) and the result saves;
it becomes visible back on the doctor's encounter/Procedures view.
**Result:** ⬜ pass ⬜ fail
**Notes / change needed:** _______________________________________________

---

## 8 · Pharmacy — dispense from in-house stock  (login `pharm1`)

**Navigate:** open Mwajuma's chart → **Prescriptions** → the Artemether-Lumefantrine line →
**Dispense**. (Alternative: dispense via the Fee Sheet **Products** picker.)
**Do:**
- [ ] Dispense the prescribed quantity. Confirm a dispense/label is produced.
- [ ] (Optional) note the on-hand quantity before vs after.
- [ ] Set status **Awaiting Pharmacy (WP)** then proceed to discharge.

**Expected:** dispense succeeds and **deducts** from inventory (a sale is recorded).
**Result:** ⬜ pass ⬜ fail
**Notes / change needed:** _______________________________________________

---

## 9 · Discharge — close the visit, queue shrinks  (login `doctor1`)

**Navigate:** open today's encounter.
**Do:**
- [ ] Set a discharge disposition on the encounter (e.g. "Discharged — home").
- [ ] Set final status **Completed / Discharged (CM)** on the Flow Board.
- [ ] Re-open the Flow Board — confirm Mwajuma **drops off your active queue** (CM is a
      check-out status; rolls off within ~1 min via `checkout_roll_off`).

**Expected:** visit closes; the doctor's active list **decreases** by one.
**Result:** ⬜ pass ⬜ fail
**Notes / change needed:** _______________________________________________

---

## Known design notes (expected behaviour — not bugs)

- The **Flow Board status is a manual signal** — each station sets it; nothing auto-advances.
- **Prescription → dispense is not one click**: the doctor records the Rx, the pharmacist
  dispenses separately. The in-house dispense deducts inventory; a prescription with no
  stock can't be dispensed until a Purchase/stock entry exists.
- A drug with **zero inventory** will refuse to dispense ("inventory not available"). Only
  ~18 seeded drugs have stock; others need a stock entry first.
- `lab1` can view lab orders and now enter/sign results; `nurse1`/`pharm1` cannot sign
  (by design — dedicated Lab role).
- **Only `pharm1` can dispense** (dedicated Pharmacy role). `nurse1`, `lab1` and `doctor1`
  are **Not Authorized** for the Inventory/Dispense screens by design.
- **Routing to a doctor** = the appointment's Provider. The Flow Board auto-shows the
  logged-in doctor their own patients; with one doctor it's effectively the whole TR queue.
- The nurse triages on the **same encounter** the cashier/doctor use — it auto-creates at
  registration (RG is now a check-in status).

## Overall sign-off

Workflow end-to-end: ⬜ works ⬜ works with notes ⬜ blocked.
Top changes to make (carry into `PROGRESS.md` / next session):
1. ________________________________________________
2. ________________________________________________
3. ________________________________________________
