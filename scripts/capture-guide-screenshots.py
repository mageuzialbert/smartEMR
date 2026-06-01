#!/usr/bin/env python3
"""
Capture ANNOTATED smartEMR (OpenEMR) screenshots for the role-based OPD user guide (v2.0).

Two jobs:
  1. Drive the TEST patient (pid=2, "TEST Mwajuma Hassan") through today's OPD workflow by calling
     scripts/guide-scenario.php inside the openemr container (the real OPD service layer), so each
     role worklist renders in the exact state we want to photograph.
  2. For each screen, log in as the right role, navigate to the real custom-module URL, draw numbered
     orange badges on specific buttons/fields (injected DOM overlay), and save the screenshot.

The browser runs in the dev-easy `selenium` container (grid at http://localhost:4444) and reaches
OpenEMR over the Docker network as http://openemr. Credentials come from SECRETS.txt at runtime —
nothing secret is hard-coded. Only the TEST patient is ever touched.

Each screenshot's numbered badges line up with the legend authored in scripts/build-user-guide.js
(same numbers). "target not found" warnings tell you which selector to fix; the run never aborts.

Usage:   python3 scripts/capture-guide-screenshots.py
Output:  docs/guide-screenshots/*.png
"""
import os
import re
import subprocess
import sys
import time

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.chrome.options import Options

GRID = "http://localhost:4444/wd/hub"
BASE = "http://openemr"           # internal Docker hostname the browser uses
SITE = "default"
PID = 2                           # TEST Mwajuma Hassan
WIN_W, WIN_H = 1440, 1024
HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SECRETS = os.path.join(HERE, "SECRETS.txt")
OUT = os.path.join(HERE, "docs", "guide-screenshots")
COMPOSE = os.path.join(HERE, "docker", "development-easy", "docker-compose.yml")
CONTAINER_APP = "/var/www/localhost/htdocs/openemr"

# Per-department order URL is filled once we know the seeded provider ids.
LAB_PPID = 0
RAD_PPID = 0
ENC = 0

# ── Annotation overlay injected into the page before each screenshot ────────────
# annotate(targets): targets = [{selector, n, label, position}]. Draws an orange ring on each
# element and a numbered circular badge beside it (position: left|right|top|bottom). Elements that
# are missing or outside the current viewport are skipped and reported, so every drawn badge is
# guaranteed visible in the shot. Returns {found:[n...], missing:[n...], offscreen:[n...]}.
ANNOTATE_JS = r"""
var targets = arguments[0];
var old = document.getElementById('__opd_ovl'); if (old) { old.remove(); }
var ovl = document.createElement('div');
ovl.id = '__opd_ovl';
ovl.style.cssText = 'position:fixed;left:0;top:0;width:100%;height:100%;pointer-events:none;z-index:2147483647;';
document.body.appendChild(ovl);
var vw = window.innerWidth, vh = window.innerHeight;
var rep = {found:[], missing:[], offscreen:[]};
for (var i = 0; i < targets.length; i++) {
  var t = targets[i], el = null;
  try {
    if (t.selector && t.selector.xpath) {
      el = document.evaluate(t.selector.xpath, document, null, 9, null).singleNodeValue;
    } else {
      el = document.querySelector(t.selector);
    }
  } catch (e) { el = null; }
  if (!el) { rep.missing.push(t.n); continue; }
  var r = el.getBoundingClientRect();
  if (r.width === 0 && r.height === 0) { rep.missing.push(t.n); continue; }
  if (r.bottom < 4 || r.top > vh - 4 || r.right < 4 || r.left > vw - 4) { rep.offscreen.push(t.n); continue; }
  var ring = document.createElement('div');
  ring.style.cssText = 'position:fixed;border:3px solid #E8470A;border-radius:4px;box-sizing:border-box;'
    + 'left:' + r.left + 'px;top:' + r.top + 'px;width:' + r.width + 'px;height:' + r.height + 'px;';
  ovl.appendChild(ring);
  var d = 30, gap = 8, pos = t.position || 'left';
  var bx = r.left - d - gap, by = r.top + r.height / 2 - d / 2;
  if (pos === 'right')  { bx = r.right + gap; by = r.top + r.height / 2 - d / 2; }
  if (pos === 'top')    { bx = r.left + r.width / 2 - d / 2; by = r.top - d - gap; }
  if (pos === 'bottom') { bx = r.left + r.width / 2 - d / 2; by = r.bottom + gap; }
  bx = Math.min(Math.max(2, bx), vw - d - 2);
  by = Math.min(Math.max(2, by), vh - d - 2);
  var badge = document.createElement('div');
  badge.textContent = t.n;
  badge.style.cssText = 'position:fixed;width:' + d + 'px;height:' + d + 'px;line-height:' + d + 'px;'
    + 'text-align:center;border-radius:50%;background:#E8470A;color:#fff;font-family:Arial,sans-serif;'
    + 'font-weight:bold;font-size:17px;box-shadow:0 0 0 3px #fff;left:' + bx + 'px;top:' + by + 'px;';
  ovl.appendChild(badge);
  rep.found.push(t.n);
}
return rep;
"""


def load_creds():
    """Parse SECRETS.txt -> {username: password}."""
    creds = {}
    with open(SECRETS) as fh:
        for line in fh:
            m = re.search(r"OpenEMR admin password:\s*(\S+)", line)
            if m:
                creds["admin"] = m.group(1)
                continue
            m = re.match(r"\s*\[\d+\]\s+(\w+)\s+password:\s*(\S+)", line)
            if m:
                creds[m.group(1)] = m.group(2)
    return creds


def stage(name):
    """Advance the workflow by running one guide-scenario.php stage in the container."""
    cmd = ["docker", "compose", "-f", COMPOSE, "exec", "-T", "openemr",
           "php", CONTAINER_APP + "/scripts/guide-scenario.php", "--stage=" + name]
    res = subprocess.run(cmd, capture_output=True, text=True)
    out = (res.stdout or "").strip()
    err = "\n".join(l for l in (res.stderr or "").splitlines() if "PHP Deprecated" not in l).strip()
    for line in out.splitlines():
        print(f"    [scenario:{name}] {line}")
    if res.returncode != 0:
        print(f"    !! scenario stage '{name}' failed: {err}")
    return out


def fetch_vars():
    """Read ENC / LAB_PPID / RAD_PPID from the scenario driver."""
    global ENC, LAB_PPID, RAD_PPID
    out = stage("vars")
    kv = dict(re.findall(r"(\w+)=(.*)", out))
    ENC = int(kv.get("ENC", "0") or 0)
    LAB_PPID = int(kv.get("LAB_PPID", "0") or 0)
    RAD_PPID = int(kv.get("RAD_PPID", "0") or 0)
    print(f"    vars: ENC={ENC} LAB_PPID={LAB_PPID} RAD_PPID={RAD_PPID}")


def make_driver():
    opts = Options()
    opts.add_argument(f"--window-size={WIN_W},{WIN_H}")
    opts.add_argument("--hide-scrollbars")
    d = webdriver.Remote(command_executor=GRID, options=opts)
    d.set_window_size(WIN_W, WIN_H)
    d.set_page_load_timeout(60)
    return d


def login(d, user, pw):
    d.get(f"{BASE}/interface/login/login.php?site={SITE}")
    time.sleep(1.5)
    d.find_element(By.NAME, "authUser").clear()
    d.find_element(By.NAME, "authUser").send_keys(user)
    d.find_element(By.NAME, "clearPass").clear()
    d.find_element(By.NAME, "clearPass").send_keys(pw)
    try:
        d.find_element(By.CSS_SELECTOR, "button[type=submit], input[type=submit]").click()
    except Exception:
        d.find_element(By.NAME, "clearPass").submit()
    time.sleep(6)  # frameset + tabs load


def logout(d):
    try:
        d.get(f"{BASE}/interface/logout.php?site={SITE}")
        time.sleep(2)
    except Exception:
        pass


def set_context(d, enc):
    """Set session patient + encounter so the native form pages render in context."""
    d.get(f"{BASE}/interface/patient_file/summary/demographics.php?set_pid={PID}&set_encounterid={enc}")
    time.sleep(2)
    d.get(f"{BASE}/interface/patient_file/encounter/encounter_top.php?set_encounter={enc}")
    time.sleep(2)


def shot(d, name):
    path = os.path.join(OUT, name)
    d.save_screenshot(path)
    size = os.path.getsize(path) if os.path.exists(path) else 0
    print(f"  saved {name} ({size} bytes)")


def grab(d, name, url, targets=None, wait=3):
    """Navigate, optionally annotate the listed targets, then screenshot."""
    try:
        d.get(BASE + url)
        time.sleep(wait)
    except Exception as e:
        print(f"  !! {name} navigation failed: {e}")
    if targets:
        try:
            rep = d.execute_script(ANNOTATE_JS, targets)
            miss = (rep or {}).get("missing", []) + (rep or {}).get("offscreen", [])
            if miss:
                print(f"  ?? {name}: badges not drawn (missing/offscreen): {sorted(set(miss))}")
        except Exception as e:
            print(f"  !! {name}: annotation error: {e}")
        time.sleep(0.3)
    shot(d, name)
    if targets:
        try:
            d.execute_script("var o=document.getElementById('__opd_ovl'); if(o)o.remove();")
        except Exception:
            pass


# Short selector helpers
def css(sel, n, label, pos="left"):
    return {"selector": sel, "n": n, "label": label, "position": pos}


def xp(path, n, label, pos="left"):
    return {"selector": {"xpath": path}, "n": n, "label": label, "position": pos}


OPD = "/interface/modules/custom_modules/oe-module-opd/public"


def main():
    os.makedirs(OUT, exist_ok=True)
    creds = load_creds()
    needed = ["admin", "reception1", "cashier1", "nurse1", "doctor1", "lab1", "radiology1", "pharm1"]
    missing = [u for u in needed if u not in creds]
    if missing:
        print(f"ERROR: missing creds for {missing} in SECRETS.txt", file=sys.stderr)
        sys.exit(1)

    print("== Reset today's TEST-patient workflow ==")
    stage("reset")

    d = make_driver()
    try:
        # ── 01–03  Login + orientation ─────────────────────────────────────────
        print("orientation:")
        grab(d, "01_login.png", f"/interface/login/login.php?site={SITE}", wait=2, targets=[
            css("input[name=authUser]", 1, "Type your username"),
            css("input[name=clearPass]", 2, "Type your password"),
            css("button[type=submit], #login-button, input[type=submit]", 3, "Click Login", pos="bottom"),
        ])
        login(d, "admin", creds["admin"])
        time.sleep(3)
        shot(d, "02_main_dashboard.png")   # post-login frameset (top menu lives in a frame — caption explains it)
        logout(d)

        # ── 04–05  Reception (BEFORE starting the visit — pristine screens) ─────
        print("reception1:")
        login(d, "reception1", creds["reception1"])
        grab(d, "04_reception_search.png", f"{OPD}/reception_register.php?search=Mwajuma", wait=3, targets=[
            css("input[name=search]", 1, "Type the patient's name or Registration No."),
            css("button.btn-primary[type=submit]", 2, "Click Search", pos="right"),
            css("a.btn-success", 3, "Click Select next to the right patient", pos="left"),
        ])
        grab(d, "05_reception_allocate.png", f"{OPD}/reception_register.php?pid={PID}", wait=3, targets=[
            css("select[name=pc_catid]", 1, "Choose the clinic the patient is visiting"),
            css("button.btn-primary[type=submit]", 2, "Click Allocate to clinic & bill", pos="right"),
        ])
        logout(d)

        # Start the visit (creates today's appointment at RG + bills consultation).
        stage("register")
        fetch_vars()

        # ── 06–07  Cashier (registration + consultation outstanding) ───────────
        print("cashier1:")
        login(d, "cashier1", creds["cashier1"])
        grab(d, "06_cashier_worklist.png", f"{OPD}/cashier_checkout.php", wait=3, targets=[
            css("table tbody tr td .badge, table tbody tr .badge", 1, "Visit status (Registered / Paid)"),
            xp("(//a[contains(.,'Open') or contains(.,'Collect')])[1]", 2, "Click Open / Collect", pos="left"),
        ])
        grab(d, "07_cashier_collect.png", f"{OPD}/cashier_collect.php?pid={PID}&encounter={ENC}", wait=3, targets=[
            css("input[type=checkbox][name^=collect]", 1, "Tick each line you are collecting"),
            css("input[type=number][name^=amount]", 2, "Adjust the amount for a part payment", pos="right"),
            css("select[name=method]", 3, "Choose the payment method", pos="left"),
            css("button.btn-primary[type=submit]", 4, "Click Collect selected", pos="right"),
        ])
        logout(d)
        stage("pay-consult")

        # ── 08–09  Nurse (triage queue + vitals) ───────────────────────────────
        print("nurse1:")
        login(d, "nurse1", creds["nurse1"])
        grab(d, "08_triage_queue.png", f"{OPD}/triage_queue.php", wait=3, targets=[
            xp("(//a[contains(.,'Record vitals')])[1]", 1, "Click Record vitals"),
            css("select[name=provider_id]", 2, "Choose the doctor to send the patient to", pos="top"),
            css("button.btn-success[type=submit]", 3, "Click Triaged → Doctor", pos="right"),
        ])
        set_context(d, ENC)
        grab(d, "09_vitals_form.png", "/interface/forms/vitals/new.php", wait=4, targets=[
            css("#weight_input_metric", 1, "Weight (kg)"),
            css("input[name='bps']", 2, "Blood pressure — systolic", pos="left"),
            css("input[name='pulse']", 3, "Pulse (beats/min)", pos="left"),
            css("#temperature_input_metric", 4, "Temperature (°C)"),
            xp("//button[contains(.,'Save')]", 5, "Click Save", pos="left"),
        ])
        logout(d)
        stage("triage")

        # ── 10–14  Doctor (queue, OPD note, lab + radiology orders, prescribe) ──
        print("doctor1:")
        login(d, "doctor1", creds["doctor1"])
        grab(d, "10_doctor_queue.png", f"{OPD}/doctor_queue.php", wait=3, targets=[
            css("select[name=clinic]", 1, "Pick which clinic queue to view", pos="right"),
            css("button.btn-success[type=submit]", 2, "Click Open to start the consultation"),
            css("button.btn-outline-danger[type=submit]", 3, "Click Discharge to close the visit", pos="right"),
        ])
        stage("consult")
        set_context(d, ENC)
        grab(d, "11_opd_note.png", "/interface/forms/LBF/new.php?formname=LBFopd_visit", wait=4, targets=[
            css(".select2-container, select[multiple]", 1, "Chief complaints — pick from the list or type"),
            css("textarea", 2, "Type the history and examination findings", pos="right"),
        ])
        order_targets = [
            css("select[name='form_lab_id']", 1, "Department — where the order is sent"),
            css("input[name='form_proc_type_desc[0]']", 2, "Click to choose the test / procedure", pos="top"),
            css("#bn_save_exit", 3, "Click Send request to place the order", pos="top"),
        ]
        grab(d, "12_order_lab.png", f"/interface/forms/procedure_order/new.php?lab_id={LAB_PPID}", wait=4, targets=order_targets)
        grab(d, "13_order_radiology.png", f"/interface/forms/procedure_order/new.php?lab_id={RAD_PPID}", wait=4, targets=order_targets)
        grab(d, "14_prescribe.png", f"{OPD}/prescribe.php?pid={PID}&encounter={ENC}", wait=3, targets=[
            css("input.rx-drug", 1, "Type the drug name (pick from the list)"),
            css("input[name='dose[]']", 2, "Dose", pos="top"),
            css("select[name='freq[]']", 3, "How often (OD/BD/TDS…)", pos="top"),
            css("input[name='days[]']", 4, "For how many days", pos="top"),
            css("input.rx-qty", 5, "Quantity — calculated for you", pos="top"),
            css("#rxadd", 6, "Add another drug"),
            css("button.btn-primary[type=submit]", 7, "Click Save prescriptions", pos="right"),
        ])
        logout(d)

        # Create the orders so the lab/radiology worklists populate, then show the cashier the
        # new (still unpaid) order lines, then clear them.
        stage("order-lab")
        stage("order-rad")
        print("cashier1 (orders):")
        login(d, "cashier1", creds["cashier1"])
        grab(d, "15_cashier_collect_orders.png", f"{OPD}/cashier_collect.php?pid={PID}&encounter={ENC}", wait=3, targets=[
            xp("//td[contains(.,'LAB-') or contains(.,'RAD-')][1]", 1, "Lab / radiology order charges appear here"),
            css("button.btn-primary[type=submit]", 2, "Collect the order charges too", pos="right"),
        ])
        logout(d)
        stage("pay-orders")

        # ── 16–17  Lab + Radiology results worklists ───────────────────────────
        print("lab1:")
        login(d, "lab1", creds["lab1"])
        grab(d, "16_lab_results.png", f"{OPD}/results_queue.php", wait=3, targets=[
            css("span.badge.bg-success", 1, "Paid — results may now be entered", pos="top"),
            css("button.btn-success", 2, "Click Enter results", pos="left"),
        ])
        logout(d)
        print("radiology1:")
        login(d, "radiology1", creds["radiology1"])
        grab(d, "17_radiology_results.png", f"{OPD}/results_queue.php", wait=3, targets=[
            css("td", 1, "Your department's imaging orders", pos="right"),
            css("button.btn-success, button.btn-outline-secondary", 2, "Enter results once the order is paid", pos="left"),
        ])
        logout(d)

        # ── 18–19  Pharmacy (confirm + dispense) ───────────────────────────────
        stage("prescribe")
        stage("pharm-confirm")
        stage("pay-meds")
        print("pharm1:")
        login(d, "pharm1", creds["pharm1"])
        grab(d, "18_pharmacy_queue.png", f"{OPD}/pharmacy_queue.php", wait=3, targets=[
            css("span.badge", 1, "Each medicine's status"),
            css("input[name=fee]", 2, "Set / confirm the fee", pos="top"),
            css("button.btn-primary[type=submit]", 3, "Click Confirm & bill", pos="right"),
            css("button.btn-success[type=submit]", 4, "Click Dispense (only after payment)", pos="right"),
        ])
        grab(d, "19_pharmacy_inventory.png", "/interface/drugs/drug_inventory.php", wait=4, targets=[
            css("input.btn-primary[value='Add Drug'], input[value='Add Drug']", 1, "Add a new medicine to the catalogue", pos="top"),
            xp("(//table//a[normalize-space(.)='Tran'] | //table//button[normalize-space(.)='Tran'] | //table//input[@value='Tran'])[1]", 2, "Record a stock delivery (Tran → Purchase)", pos="left"),
            css("input[type=search], input[aria-controls]", 3, "Search the catalogue", pos="left"),
        ])
        logout(d)

        # ── 20  Discharge / Patient Flow Board ─────────────────────────────────
        print("doctor1 (flow board):")
        login(d, "doctor1", creds["doctor1"])
        grab(d, "20_flow_board.png", "/interface/patient_tracker/patient_tracker.php?skip_timeout_reset=1", wait=4, targets=[
            xp("(//tbody//td[contains(.,'Awaiting') or contains(.,'With Doctor') or contains(.,'Paid') or contains(.,'Triaged') or contains(.,'Registered')])[1]", 1, "Each patient's current status", pos="right"),
            css("button.btn-filter", 2, "Filter the board by status / date", pos="right"),
        ])
        logout(d)

        print("\nDone. Annotated screenshots in", OUT)
    finally:
        d.quit()


if __name__ == "__main__":
    main()
