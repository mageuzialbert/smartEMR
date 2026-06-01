<?php

/**
 * OPD User-Guide screenshot scenario driver.
 *
 * Drives the TEST patient (pid=2, "TEST Mwajuma Hassan") through today's OPD workflow using the
 * real OPD service layer — the same logic the staff screens call — so the role worklists
 * (cashier / triage / doctor / results / pharmacy) render in the exact state we need to photograph
 * for the user guide. The companion Selenium script (capture-guide-screenshots.py) calls this
 * between screenshots to advance the workflow, then logs in per role and captures + annotates.
 *
 * SAFETY: every write is hard-scoped to pid=2 and TODAY. `--stage=reset` deletes ONLY today's
 * generated rows for pid=2 so a capture run is repeatable; it touches no other patient and nothing
 * dated before today.
 *
 * Run inside the openemr container, e.g.:
 *   docker compose -f docker/development-easy/docker-compose.yml exec -T openemr \
 *     php /var/www/localhost/htdocs/openemr/scripts/guide-scenario.php --stage=register
 *
 * Stages (run in order): reset, register, pay-consult, triage, consult, order-lab, order-rad,
 *   pay-orders, prescribe, pharm-confirm, pay-meds, dispense, discharge, status.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

$ignoreAuth = true;
$_GET['site'] = 'default';
$_SESSION['site_id'] = 'default';
$_SERVER['HTTP_HOST'] = 'localhost';
require_once(__DIR__ . '/../interface/globals.php');

use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\OpdWorkflow\Service\OpdClinicService;
use OpenEMR\Modules\OpdWorkflow\Service\OpdGatingService;
use OpenEMR\Modules\OpdWorkflow\Service\OpdOrderBillingService;
use OpenEMR\Modules\OpdWorkflow\Service\OpdPharmacyService;
use OpenEMR\Modules\OpdWorkflow\Service\OpdPrescribeService;
use OpenEMR\Modules\OpdWorkflow\Service\OpdVisitService;

require_once($GLOBALS['fileroot'] . '/library/forms.inc.php');

// ── Constants / guards ──────────────────────────────────────────────────────
const PID = 2;                       // TEST Mwajuma Hassan — the only patient we ever touch
const PAY_METHOD = 'cash';           // list_options.option_id for payment_method "Cash"
const EPS = 0.005;

if (PID !== 2) {                     // belt-and-braces: never run against a real pid
    fwrite(STDERR, "Refusing to run: PID must be 2 (TEST patient).\n");
    exit(2);
}

// Attribute writes to admin (any active user works; admin always exists).
$admin = sqlQuery("SELECT id, username FROM users WHERE username = 'admin' AND active = 1 LIMIT 1");
$_SESSION['authUser'] = $admin['username'] ?? 'admin';
$_SESSION['authUserID'] = (int)($admin['id'] ?? 1);
$_SESSION['authProvider'] = 'Default';

// ── CLI args ────────────────────────────────────────────────────────────────
$stage = '';
foreach ($argv as $a) {
    if (strpos($a, '--stage=') === 0) {
        $stage = substr($a, 8);
    }
}
if ($stage === '') {
    fwrite(STDERR, "Usage: guide-scenario.php --stage=<reset|register|pay-consult|triage|consult|order-lab|order-rad|pay-orders|prescribe|pharm-confirm|pay-meds|dispense|discharge|status>\n");
    exit(1);
}

function out(string $msg): void
{
    echo $msg . "\n";
}

/** Today's active (non-discharged) pid=2 visit across clinics: ['eid','encounter','catid','status']. */
function todaysVisit(): ?array
{
    $row = sqlQuery(
        "SELECT e.pc_eid, e.pc_catid, e.pc_apptstatus, fe.encounter
         FROM openemr_postcalendar_events e
         LEFT JOIN form_encounter fe ON fe.pid = e.pc_pid AND fe.date = CONCAT(e.pc_eventDate, ' 00:00:00')
         WHERE e.pc_pid = ? AND e.pc_eventDate = CURDATE() AND e.pc_recurrtype = 0
         ORDER BY e.pc_eid DESC LIMIT 1",
        [PID]
    );
    if (!$row || empty($row['encounter'])) {
        return null;
    }
    return [
        'eid' => (int)$row['pc_eid'],
        'encounter' => (int)$row['encounter'],
        'catid' => (int)$row['pc_catid'],
        'status' => (string)$row['pc_apptstatus'],
    ];
}

/** Pay every outstanding charge line whose code matches $predicate (fn(string $code): bool). */
function collect(int $pid, int $enc, callable $predicate, string $label): void
{
    $inv = OpdGatingService::invoiceSummary($pid, $enc);
    $allocations = [];
    foreach ($inv as $key => $row) {
        $bal = (float)($row['bal'] ?? 0);
        if ($bal <= EPS) {
            continue;
        }
        // key is the code, or "code:modifier", or "PROD:<drug_id>".
        $isProd = strpos($key, 'PROD:') === 0;
        $code = $key;
        $modifier = '';
        if (!$isProd && strpos($key, ':') !== false) {
            [$code, $modifier] = explode(':', $key, 2);
        }
        if (!$predicate($code)) {
            continue;
        }
        $allocations[] = [
            'code_type' => $isProd ? 'PROD' : OpdVisitService::CONSULT_CODE_TYPE,
            'code' => $code,
            'modifier' => $modifier,
            'amount' => $bal,
        ];
    }
    if (empty($allocations)) {
        out("  ($label) nothing outstanding to collect.");
        return;
    }
    $total = array_sum(array_column($allocations, 'amount'));
    (new OpdGatingService())->recordLinePayment($pid, $enc, $allocations, PAY_METHOD, 'guide-scenario');
    out("  ($label) collected TSh " . number_format($total, 0) . " across " . count($allocations) . " line(s).");
}

/** Create a procedure order for one department leaf + auto-bill it. Returns [orderId, code, name]. */
function createOrder(int $pid, int $enc, int $providerId, string $deptName, array $preferCodes): array
{
    $dept = sqlQuery("SELECT ppid FROM procedure_providers WHERE name = ? AND active = 1 ORDER BY ppid LIMIT 1", [$deptName]);
    $ppid = (int)($dept['ppid'] ?? 0);
    if ($ppid <= 0) {
        throw new \RuntimeException("Department provider not found: $deptName");
    }
    // Reuse an existing order for this dept on this encounter (the opener does the same).
    $existing = sqlQuery(
        "SELECT procedure_order_id FROM procedure_order WHERE encounter_id = ? AND lab_id = ? AND activity = 1 ORDER BY procedure_order_id DESC LIMIT 1",
        [$enc, $ppid]
    );
    // Pick a leaf procedure_type (prefer the requested codes; else first orderable leaf).
    $leaf = null;
    foreach ($preferCodes as $pc) {
        $leaf = sqlQuery(
            "SELECT procedure_type_id, procedure_code, name FROM procedure_type
             WHERE lab_id = ? AND procedure_type = 'ord' AND activity = 1 AND procedure_code = ? LIMIT 1",
            [$ppid, $pc]
        );
        if ($leaf) {
            break;
        }
    }
    if (!$leaf) {
        $leaf = sqlQuery(
            "SELECT procedure_type_id, procedure_code, name FROM procedure_type
             WHERE lab_id = ? AND procedure_type = 'ord' AND activity = 1 AND procedure_code != '' ORDER BY seq LIMIT 1",
            [$ppid]
        );
    }
    if (!$leaf) {
        throw new \RuntimeException("No orderable procedure under $deptName.");
    }

    if (!empty($existing['procedure_order_id'])) {
        $orderId = (int)$existing['procedure_order_id'];
    } else {
        $orderId = (int)sqlInsert(
            "INSERT INTO procedure_order
               (provider_id, patient_id, encounter_id, date_ordered, order_status, activity, lab_id, procedure_order_type)
             VALUES (?, ?, ?, NOW(), 'pending', 1, ?, 'laboratory_test')",
            [$providerId, $pid, $enc, $ppid]
        );
        // Make it a real encounter form (so it shows on the chart and the opener can reopen it).
        addForm($enc, 'Procedure Order', $orderId, 'procedure_order', $pid, '1');
        // One order line carrying the HCPCS service code (procedure_code), which drives auto-billing.
        sqlInsert(
            "INSERT INTO procedure_order_code
               (procedure_order_id, procedure_order_seq, procedure_code, procedure_name, procedure_order_title, do_not_send)
             VALUES (?, 1, ?, ?, 'procedure', 0)",
            [$orderId, $leaf['procedure_code'], $leaf['name']]
        );
    }
    // Auto-bill the order's service code onto the encounter (idempotent).
    (new OpdOrderBillingService())->syncOrderCharges($orderId);
    return [$orderId, (string)$leaf['procedure_code'], (string)$leaf['name']];
}

// ── Stage dispatch ────────────────────────────────────────────────────────────
try {
    switch ($stage) {
        case 'reset': {
            // Delete ONLY today's generated rows for pid=2 (repeatable capture). Hard pid=2 + today scope.
            $encs = [];
            $res = sqlStatement("SELECT encounter FROM form_encounter WHERE pid = ? AND date >= CURDATE()", [PID]);
            while ($r = sqlFetchArray($res)) {
                $encs[] = (int)$r['encounter'];
            }
            foreach ($encs as $enc) {
                // procedure orders + codes + reports for this encounter
                $ords = [];
                $ores = sqlStatement("SELECT procedure_order_id FROM procedure_order WHERE encounter_id = ?", [$enc]);
                while ($o = sqlFetchArray($ores)) {
                    $ords[] = (int)$o['procedure_order_id'];
                }
                foreach ($ords as $oid) {
                    sqlStatement("DELETE FROM procedure_report WHERE procedure_order_id = ?", [$oid]);
                    sqlStatement("DELETE FROM procedure_order_code WHERE procedure_order_id = ?", [$oid]);
                }
                sqlStatement("DELETE FROM procedure_order WHERE encounter_id = ?", [$enc]);
                sqlStatement("DELETE FROM drug_sales WHERE pid = ? AND encounter = ?", [PID, $enc]);
                sqlStatement("DELETE FROM prescriptions WHERE patient_id = ? AND encounter = ?", [PID, $enc]);
                sqlStatement("DELETE FROM ar_activity WHERE pid = ? AND encounter = ?", [PID, $enc]);
                sqlStatement("DELETE FROM billing WHERE pid = ? AND encounter = ?", [PID, $enc]);
                sqlStatement("DELETE FROM forms WHERE pid = ? AND encounter = ?", [PID, $enc]);
                sqlStatement("DELETE FROM form_encounter WHERE pid = ? AND encounter = ?", [PID, $enc]);
            }
            sqlStatement("DELETE FROM ar_session WHERE patient_id = ? AND post_to_date >= CURDATE()", [PID]);
            // today's appointments + flow-board rows
            $eres = sqlStatement("SELECT pc_eid FROM openemr_postcalendar_events WHERE pc_pid = ? AND pc_eventDate = CURDATE()", [PID]);
            while ($e = sqlFetchArray($eres)) {
                sqlStatement("DELETE FROM patient_tracker_element WHERE pt_tracker_id IN (SELECT id FROM patient_tracker WHERE pid = ? AND apptdate = CURDATE())", [PID]);
            }
            sqlStatement("DELETE FROM patient_tracker WHERE pid = ? AND apptdate = CURDATE()", [PID]);
            sqlStatement("DELETE FROM openemr_postcalendar_events WHERE pc_pid = ? AND pc_eventDate = CURDATE()", [PID]);
            out("reset: cleared today's workflow rows for pid=" . PID . " (" . count($encs) . " encounter(s)).");
            break;
        }

        case 'register': {
            $clinicSvc = new OpdClinicService();
            $catid = 0;
            foreach ($clinicSvc->getClinics() as $c) {
                if (($c['consultation_code'] ?? '') === 'CONS-GP') {
                    $catid = (int)$c['pc_catid'];
                    break;
                }
            }
            if (!$catid) {
                $clinics = $clinicSvc->getClinics();
                $catid = (int)($clinics[0]['pc_catid'] ?? 0);
            }
            if (!$catid) {
                throw new \RuntimeException("No OPD clinic configured.");
            }
            $r = (new OpdVisitService())->startVisit(PID, $catid, null);
            out("register: clinic=" . $r['clinic'] . " encounter=#" . $r['encounter'] . " (reused=" . ($r['reused'] ? 'yes' : 'no') . ").");
            break;
        }

        case 'pay-consult': {
            $v = todaysVisit();
            if (!$v) {
                throw new \RuntimeException("No visit today — run register first.");
            }
            // Pay registration + consultation (everything that is NOT a lab/rad/proc order or a product).
            collect(PID, $v['encounter'], function (string $code) {
                return !preg_match('/^(LAB-|RAD-|PROC-|PROD:)/', $code);
            }, 'reg+consult');
            (new OpdGatingService())->activateIfPaid($v['eid'], PID, $v['encounter']);
            out("pay-consult: visit advanced toward PD (paid → awaiting triage).");
            break;
        }

        case 'triage': {
            $v = todaysVisit();
            if (!$v) {
                throw new \RuntimeException("No visit today.");
            }
            $doc = sqlQuery("SELECT id FROM users WHERE username = 'doctor1' LIMIT 1");
            $docId = (int)($doc['id'] ?? 0);
            $svc = new OpdVisitService();
            if ($docId) {
                $svc->assignProvider($v['eid'], $v['encounter'], $docId);
            }
            $svc->setStatus($v['eid'], 'TR', $v['encounter']);
            out("triage: assigned doctor1 (id=$docId), status → TR.");
            break;
        }

        case 'consult': {
            $v = todaysVisit();
            if (!$v) {
                throw new \RuntimeException("No visit today.");
            }
            (new OpdVisitService())->setStatus($v['eid'], 'WD', $v['encounter']);
            out("consult: status → WD (with doctor).");
            break;
        }

        case 'order-lab': {
            $v = todaysVisit();
            if (!$v) {
                throw new \RuntimeException("No visit today.");
            }
            $doc = sqlQuery("SELECT id FROM users WHERE username = 'doctor1' LIMIT 1");
            [$oid, $code, $name] = createOrder(PID, $v['encounter'], (int)($doc['id'] ?? 0), 'Clinic Laboratory',
                ['LAB-MRDT', 'LAB-CBC', 'LAB-UA']);
            (new OpdVisitService())->setStatus($v['eid'], 'WL', $v['encounter']);
            out("order-lab: order #$oid ($code — $name), status → WL.");
            break;
        }

        case 'order-rad': {
            $v = todaysVisit();
            if (!$v) {
                throw new \RuntimeException("No visit today.");
            }
            $doc = sqlQuery("SELECT id FROM users WHERE username = 'doctor1' LIMIT 1");
            [$oid, $code, $name] = createOrder(PID, $v['encounter'], (int)($doc['id'] ?? 0), 'Clinic Radiology',
                ['RAD-CXR', 'RAD-XLIMB', 'RAD-USABD']);
            out("order-rad: order #$oid ($code — $name).");
            break;
        }

        case 'pay-orders': {
            $v = todaysVisit();
            if (!$v) {
                throw new \RuntimeException("No visit today.");
            }
            collect(PID, $v['encounter'], function (string $code) {
                return (bool)preg_match('/^(LAB-|RAD-|PROC-)/', $code);
            }, 'lab+radiology orders');
            out("pay-orders: order lines cleared (results unlocked).");
            break;
        }

        case 'prescribe': {
            $v = todaysVisit();
            if (!$v) {
                throw new \RuntimeException("No visit today.");
            }
            $doc = sqlQuery("SELECT id FROM users WHERE username = 'doctor1' LIMIT 1");
            $docId = (int)($doc['id'] ?? 0);
            // Two in-stock drugs (prefer Artemether-Lumefantrine first) so the pharmacy queue can show
            // both an unconfirmed ("Confirm & bill") and a confirmed+paid ("Dispense") medicine.
            $drugs = [];
            $dres = sqlStatement(
                "SELECT d.drug_id, d.name, d.form, SUM(i.on_hand) AS oh
                 FROM drugs d JOIN drug_inventory i ON i.drug_id = d.drug_id
                 WHERE i.on_hand > 0 AND i.destroy_date IS NULL AND (i.expiration IS NULL OR i.expiration > CURDATE())
                 GROUP BY d.drug_id, d.name, d.form HAVING oh >= 6
                 ORDER BY (d.name LIKE '%Artemether%') DESC, (d.name LIKE '%Paracetamol%') DESC, d.name
                 LIMIT 2"
            );
            while ($d = sqlFetchArray($dres)) {
                $drugs[] = $d;
            }
            if (count($drugs) < 1) {
                throw new \RuntimeException("No in-stock drug found to prescribe (seed initial stock first).");
            }
            $rows = [];
            foreach ($drugs as $d) {
                $rows[] = ['drug_id' => (int)$d['drug_id'], 'dose' => '1', 'unit' => (string)$d['form'],
                    'freq' => 'BD', 'days' => '3', 'qty' => ''];
            }
            $n = (new OpdPrescribeService())->save(PID, $v['encounter'], $docId, $rows);
            $names = implode(', ', array_map(fn($d) => $d['name'], $drugs));
            out("prescribe: saved $n prescription(s): $names.");
            break;
        }

        case 'pharm-confirm': {
            $v = todaysVisit();
            if (!$v) {
                throw new \RuntimeException("No visit today.");
            }
            // Confirm only the FIRST active prescription → it gets a PROD charge (awaiting payment),
            // leaving any second one at "to_confirm" so the pharmacy screen shows both actions.
            $rx = sqlQuery(
                "SELECT id, drug_id FROM prescriptions WHERE patient_id = ? AND encounter = ? AND active = 1
                 AND drug_id > 0 AND id NOT IN (SELECT prescription_id FROM drug_sales WHERE prescription_id IS NOT NULL)
                 ORDER BY id LIMIT 1",
                [PID, $v['encounter']]
            );
            if (!$rx) {
                out("pharm-confirm: nothing to confirm.");
                break;
            }
            $pharm = new OpdPharmacyService();
            $fee = $pharm->catalogPrice((int)$rx['drug_id']);
            $fee = $fee !== null ? $fee : 500.0;   // pilot default sale fee when the drug is unpriced
            $saleId = $pharm->confirmPrescription((int)$rx['id'], (float)$fee);
            out("pharm-confirm: rx #" . (int)$rx['id'] . " → sale #$saleId (fee TSh " . number_format($fee, 0) . ", awaiting payment).");
            break;
        }

        case 'pay-meds': {
            $v = todaysVisit();
            if (!$v) {
                throw new \RuntimeException("No visit today.");
            }
            collect(PID, $v['encounter'], function (string $code) {
                return strpos($code, 'PROD:') === 0;
            }, 'medicines');
            out("pay-meds: product lines cleared (ready to dispense).");
            break;
        }

        case 'dispense': {
            $v = todaysVisit();
            if (!$v) {
                throw new \RuntimeException("No visit today.");
            }
            $pharm = new OpdPharmacyService();
            $done = 0;
            foreach ($pharm->getQueue() as $q) {
                if ($q['status'] === 'ready' && (int)$q['pid'] === PID) {
                    $pharm->dispense((int)$q['sale_id']);
                    $done++;
                }
            }
            out("dispense: dispensed $done medicine(s) (stock decremented).");
            break;
        }

        case 'discharge': {
            $v = todaysVisit();
            if (!$v) {
                throw new \RuntimeException("No visit today.");
            }
            (new OpdVisitService())->discharge($v['eid'], $v['encounter']);
            out("discharge: visit closed (status → CM).");
            break;
        }

        case 'vars': {
            // Machine-readable KEY=VALUE lines for the capture orchestrator.
            $v = todaysVisit();
            $lab = sqlQuery("SELECT ppid FROM procedure_providers WHERE name = 'Clinic Laboratory' AND active = 1 ORDER BY ppid LIMIT 1");
            $rad = sqlQuery("SELECT ppid FROM procedure_providers WHERE name = 'Clinic Radiology' AND active = 1 ORDER BY ppid LIMIT 1");
            out("ENC=" . ($v['encounter'] ?? 0));
            out("EID=" . ($v['eid'] ?? 0));
            out("STATUS=" . ($v['status'] ?? ''));
            out("LAB_PPID=" . (int)($lab['ppid'] ?? 0));
            out("RAD_PPID=" . (int)($rad['ppid'] ?? 0));
            break;
        }

        case 'status': {
            $v = todaysVisit();
            if (!$v) {
                out("status: no visit today for pid=" . PID . ".");
                break;
            }
            $bal = (new OpdGatingService())->getVisitBalance(PID, $v['encounter']);
            out("status: encounter #" . $v['encounter'] . " status=" . $v['status'] . " balance=TSh " . number_format($bal, 0));
            $inv = OpdGatingService::invoiceSummary(PID, $v['encounter']);
            foreach ($inv as $code => $row) {
                out("   - $code: chg=" . ($row['chg'] ?? 0) . " bal=" . ($row['bal'] ?? 0));
            }
            break;
        }

        default:
            fwrite(STDERR, "Unknown stage: $stage\n");
            exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "STAGE $stage FAILED: " . $e->getMessage() . "\n");
    exit(3);
}
