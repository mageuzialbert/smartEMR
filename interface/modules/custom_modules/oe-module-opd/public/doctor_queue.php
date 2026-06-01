<?php

/**
 * OPD Workflow — Doctor clinic queue (and doctor landing page).
 *
 * Shows the queue of paid, triaged patients (status TR) in a clinic. The clinic
 * dropdown is limited to the clinics this doctor is granted (opd_provider_clinic),
 * so a doctor only ever sees the queues of their own clinics. Opening a patient
 * loads their encounter, where the doctor writes the OPD note, orders labs, etc.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

require_once(__DIR__ . "/../../../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Modules\OpdWorkflow\Service\OpdClinicService;
use OpenEMR\Modules\OpdWorkflow\Service\OpdGatingService;
use OpenEMR\Modules\OpdWorkflow\Service\OpdVisitService;

// Doctor: needs clinical note access.
if (!AclMain::aclCheckCore('encounters', 'notes')) {
    echo xlt('Access denied');
    exit;
}

$clinicService = new OpdClinicService();
$gating = new OpdGatingService();
$visit = new OpdVisitService();

$providerId = (int)($_SESSION['authUserID'] ?? 0);
$myClinicIds = $clinicService->getProviderClinics($providerId);

// Names for the granted clinics (preserve clinic order).
$myClinics = [];
foreach ($clinicService->getClinics() as $c) {
    if (in_array((int)$c['pc_catid'], $myClinicIds, true)) {
        $myClinics[] = $c;
    }
}

$message = '';
$error = '';

// ── Actions: start consult (→ WD) or discharge (→ CM, ends the visit) ──────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    $eid = (int)($_POST['eid'] ?? 0);
    $enc = (int)($_POST['encounter'] ?? 0);
    $action = $_POST['action'] ?? 'start';
    try {
        if ($eid > 0 && $enc > 0) {
            if ($action === 'discharge') {
                $visit->discharge($eid, $enc);
                $message = xl('Patient discharged — visit closed.');
            } else {
                $visit->setStatus($eid, 'WD', $enc); // With Doctor
                $message = xl('Consultation started (With Doctor).');
            }
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Selected clinic (must be one the doctor is granted) ────────────────────
$selectedClinic = (int)($_GET['clinic'] ?? 0);
if (!in_array($selectedClinic, $myClinicIds, true)) {
    $selectedClinic = $myClinicIds[0] ?? 0;
}

// ── Queue: paid, active (post-triage) patients in the selected clinic ──────
// The patient stays here through the whole active visit (TR → WD → WL → LB → WP)
// until discharged (CM), so the doctor can keep editing while results are pending.
$rows = [];
if ($selectedClinic > 0) {
    $res = sqlStatement(
        "SELECT e.pc_eid, e.pc_pid, e.pc_apptstatus, e.pc_eventDate, pd.fname, pd.lname, pd.pubpid, pd.DOB, pd.sex,
                fe.encounter, e.pc_startTime, lo.title AS status_label
         FROM openemr_postcalendar_events e
         JOIN patient_data pd ON pd.pid = e.pc_pid
         LEFT JOIN form_encounter fe ON fe.pid = e.pc_pid AND fe.date = CONCAT(e.pc_eventDate, ' 00:00:00')
         LEFT JOIN list_options lo ON lo.list_id = 'apptstat' AND lo.option_id = e.pc_apptstatus
         WHERE e.pc_eventDate >= (CURDATE() - INTERVAL ? DAY)
               AND e.pc_apptstatus IN ('TR','WD','WL','LB','WP')
               AND e.pc_catid = ? AND e.pc_pid > 0
         ORDER BY FIELD(e.pc_apptstatus,'WD','TR','LB','WL','WP'), e.pc_eventDate, e.pc_startTime",
        [OpdVisitService::ACTIVE_LOOKBACK_DAYS, $selectedClinic]
    );
    while ($r = sqlFetchArray($res)) {
        $enc = (int)$r['encounter'];
        if (!$enc || !$gating->isVisitPaid((int)$r['pc_pid'], $enc)) {
            continue;
        }
        $rows[] = $r;
    }
}

$csrf = CsrfUtils::collectCsrfToken();
$self = $_SERVER['PHP_SELF'];
$webroot = $GLOBALS['webroot'];
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('OPD Doctor Queue'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <script>
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
    <h2><?php echo xlt('Doctor Queue'); ?></h2>
    <?php if ($message) { ?><div class="alert alert-success"><?php echo text($message); ?></div><?php } ?>
    <?php if ($error) { ?><div class="alert alert-danger"><?php echo text($error); ?></div><?php } ?>

    <?php if (empty($myClinics)) { ?>
        <div class="alert alert-warning"><?php echo xlt('You are not assigned to any clinic. Ask an administrator to grant you a clinic.'); ?></div>
    <?php } else { ?>
        <form method="get" action="<?php echo attr($self); ?>" class="form-inline mb-3">
            <label class="mr-2"><?php echo xlt('Clinic'); ?>:</label>
            <select name="clinic" class="form-control" onchange="this.form.submit()">
                <?php foreach ($myClinics as $c) { ?>
                    <option value="<?php echo attr($c['pc_catid']); ?>" <?php echo ((int)$c['pc_catid'] === $selectedClinic) ? 'selected' : ''; ?>>
                        <?php echo text($c['pc_catname']); ?>
                    </option>
                <?php } ?>
            </select>
        </form>

        <table class="table table-sm table-bordered align-middle">
            <thead><tr>
                <th><?php echo xlt('Patient'); ?></th>
                <th><?php echo xlt('Reg No.'); ?></th>
                <th><?php echo xlt('DOB'); ?></th>
                <th><?php echo xlt('Status'); ?></th>
                <th><?php echo xlt('Since'); ?></th>
                <th><?php echo xlt('Action'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r) { ?>
                <tr>
                    <td><?php echo text($r['lname'] . ', ' . $r['fname']); ?></td>
                    <td><?php echo text($r['pubpid']); ?></td>
                    <td><?php echo text($r['DOB']); ?></td>
                    <td><span class="badge bg-info"><?php echo text($r['status_label'] ?? $r['pc_apptstatus']); ?></span></td>
                    <td><?php echo text(substr((string)$r['pc_eventDate'], 0, 10)); ?></td>
                    <td>
                        <form method="post" action="<?php echo attr($self . '?clinic=' . (int)$selectedClinic); ?>" class="d-inline">
                            <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                            <input type="hidden" name="action" value="start">
                            <input type="hidden" name="eid" value="<?php echo attr($r['pc_eid']); ?>">
                            <input type="hidden" name="encounter" value="<?php echo attr($r['encounter']); ?>">
                            <button type="submit" class="btn btn-sm btn-success"
                                    onclick="topatient(<?php echo attr_js($r['pc_pid']); ?>, <?php echo attr_js($r['encounter']); ?>);">
                                <?php echo xlt('Open'); ?>
                            </button>
                        </form>
                        <form method="post" action="<?php echo attr($self . '?clinic=' . (int)$selectedClinic); ?>" class="d-inline"
                              onsubmit="return confirm('<?php echo xla('Discharge this patient and close the visit?'); ?>');">
                            <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                            <input type="hidden" name="action" value="discharge">
                            <input type="hidden" name="eid" value="<?php echo attr($r['pc_eid']); ?>">
                            <input type="hidden" name="encounter" value="<?php echo attr($r['encounter']); ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo xlt('Discharge'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php } ?>
            <?php if (empty($rows)) { ?>
                <tr><td colspan="6"><?php echo xlt('No active patients in this clinic.'); ?></td></tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</body>
</html>
