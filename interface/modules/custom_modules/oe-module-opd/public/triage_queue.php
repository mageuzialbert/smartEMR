<?php

/**
 * OPD Workflow — Nurse triage queue.
 *
 * Lists today's PAID patients awaiting triage (status PD). Unpaid patients never
 * appear (self-filtered on isVisitPaid as well as status). The nurse opens the
 * patient's encounter to record Vitals + the triage section of the OPD note, then
 * routes the patient to a doctor (status TR — Triaged / Awaiting Doctor).
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

require_once(__DIR__ . "/../../../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Modules\OpdWorkflow\Service\OpdGatingService;
use OpenEMR\Modules\OpdWorkflow\Service\OpdVisitService;

// Nurse: needs clinical note access.
if (!AclMain::aclCheckCore('encounters', 'notes') && !AclMain::aclCheckCore('patients', 'med')) {
    echo xlt('Access denied');
    exit;
}

$gating = new OpdGatingService();
$visit = new OpdVisitService();
$message = '';
$error = '';

// ── Handle "send to doctor" POST ───────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    $eid = (int)($_POST['eid'] ?? 0);
    $enc = (int)($_POST['encounter'] ?? 0);
    $pid = (int)($_POST['pid'] ?? 0);
    $providerId = (int)($_POST['provider_id'] ?? 0) ?: null;
    try {
        if ($eid <= 0 || $enc <= 0) {
            throw new \RuntimeException(xl('Missing visit details.'));
        }
        if (!$gating->isVisitPaid($pid, $enc)) {
            throw new \RuntimeException(xl('Visit is not paid; cannot send to doctor.'));
        }
        if ($providerId) {
            $visit->assignProvider($eid, $enc, $providerId);
        }
        $visit->setStatus($eid, 'TR', $enc);
        $message = xl('Patient sent to doctor (Triaged).');
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Triage worklist (paid, awaiting triage — active until discharged) ──────
$rows = [];
$res = sqlStatement(
    "SELECT e.pc_eid, e.pc_pid, e.pc_aid, cat.pc_catname, pd.fname, pd.lname, pd.pubpid, fe.encounter
     FROM openemr_postcalendar_events e
     JOIN openemr_postcalendar_categories cat ON cat.pc_catid = e.pc_catid AND cat.pc_constant_id LIKE 'clinic_%'
     JOIN patient_data pd ON pd.pid = e.pc_pid
     LEFT JOIN form_encounter fe ON fe.pid = e.pc_pid AND fe.date = CONCAT(e.pc_eventDate, ' 00:00:00')
     WHERE e.pc_eventDate >= (CURDATE() - INTERVAL ? DAY) AND e.pc_apptstatus = 'PD' AND e.pc_pid > 0
     ORDER BY e.pc_eventDate, e.pc_startTime",
    [OpdVisitService::ACTIVE_LOOKBACK_DAYS]
);
while ($r = sqlFetchArray($res)) {
    $enc = (int)$r['encounter'];
    if (!$enc || !$gating->isVisitPaid((int)$r['pc_pid'], $enc)) {
        continue; // defence-in-depth: never show unpaid
    }
    $rows[] = $r;
}

$providers = [];
$pres = sqlStatement("SELECT id, fname, lname FROM users WHERE authorized = 1 AND active = 1 ORDER BY lname, fname");
while ($p = sqlFetchArray($pres)) {
    $providers[] = $p;
}

$csrf = CsrfUtils::collectCsrfToken();
$self = $_SERVER['PHP_SELF'];
$webroot = $GLOBALS['webroot'];
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('OPD Triage Queue'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <script>
        // Land the nurse straight on the encounter's Vitals form (one vitals per visit; the opener
        // sets the patient/encounter session and opens the existing vitals or a new one).
        function torecordvitals(pid, enc) {
            if (top.restoreSession) { top.restoreSession(); }
            top.RTop.location = <?php echo js_escape($webroot); ?> +
                "/interface/modules/custom_modules/oe-module-opd/public/opd_vitals.php?pid=" + encodeURIComponent(pid) +
                "&encounter=" + encodeURIComponent(enc);
            return false;
        }
        // Open the full patient chart (fallback / for context).
        function topatient(pid, enc) {
            if (top.restoreSession) { top.restoreSession(); }
            top.RTop.location = <?php echo js_escape($webroot); ?> +
                "/interface/patient_file/summary/demographics.php?set_pid=" + encodeURIComponent(pid) +
                "&set_encounterid=" + encodeURIComponent(enc);
            return false;
        }
    </script>
</head>
<body class="body_top container-fluid mt-3">
    <h2><?php echo xlt('Triage Queue — Paid, Awaiting Vitals'); ?></h2>
    <?php if ($message) { ?><div class="alert alert-success"><?php echo text($message); ?></div><?php } ?>
    <?php if ($error) { ?><div class="alert alert-danger"><?php echo text($error); ?></div><?php } ?>

    <table class="table table-sm table-bordered align-middle">
        <thead><tr>
            <th><?php echo xlt('Patient'); ?></th>
            <th><?php echo xlt('Reg No.'); ?></th>
            <th><?php echo xlt('Clinic'); ?></th>
            <th><?php echo xlt('Record vitals / notes'); ?></th>
            <th><?php echo xlt('Send to doctor'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r) { ?>
            <tr>
                <td><?php echo text($r['lname'] . ', ' . $r['fname']); ?></td>
                <td><?php echo text($r['pubpid']); ?></td>
                <td><?php echo text($r['pc_catname']); ?></td>
                <td>
                    <a class="btn btn-sm btn-primary" href="#"
                       onclick="return torecordvitals(<?php echo attr_js($r['pc_pid']); ?>, <?php echo attr_js($r['encounter']); ?>)">
                        <?php echo xlt('Record vitals'); ?>
                    </a>
                    <a class="btn btn-sm btn-outline-secondary" href="#"
                       onclick="return topatient(<?php echo attr_js($r['pc_pid']); ?>, <?php echo attr_js($r['encounter']); ?>)">
                        <?php echo xlt('Open chart'); ?>
                    </a>
                </td>
                <td>
                    <form method="post" action="<?php echo attr($self); ?>" class="form-inline">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                        <input type="hidden" name="pid" value="<?php echo attr($r['pc_pid']); ?>">
                        <input type="hidden" name="eid" value="<?php echo attr($r['pc_eid']); ?>">
                        <input type="hidden" name="encounter" value="<?php echo attr($r['encounter']); ?>">
                        <select name="provider_id" class="form-control form-control-sm mr-1">
                            <option value=""><?php echo xlt('-- Keep / unassigned --'); ?></option>
                            <?php foreach ($providers as $p) { ?>
                                <option value="<?php echo attr($p['id']); ?>" <?php echo ((int)$r['pc_aid'] === (int)$p['id']) ? 'selected' : ''; ?>>
                                    <?php echo text($p['lname'] . ', ' . $p['fname']); ?>
                                </option>
                            <?php } ?>
                        </select>
                        <button class="btn btn-sm btn-success" type="submit"><?php echo xlt('Triaged → Doctor'); ?></button>
                    </form>
                </td>
            </tr>
        <?php } ?>
        <?php if (empty($rows)) { ?>
            <tr><td colspan="5"><?php echo xlt('No paid patients awaiting triage.'); ?></td></tr>
        <?php } ?>
        </tbody>
    </table>
</body>
</html>
