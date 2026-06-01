<?php

/**
 * OPD Workflow — Vitals opener (one Vitals form per encounter; nurse-friendly).
 *
 * Opens the encounter's existing Vitals form for editing (or a new one if none), so Vitals stay a
 * single instance per visit. Works both from the encounter Clinical menu (session already set) and
 * from the triage queue (sets the patient/encounter session from the GET params), letting the nurse
 * land straight on Vitals. Vitals are encounter-bound, so whoever records them (the triage nurse)
 * becomes part of the encounter the doctor sees.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

require_once(__DIR__ . "/../../../../globals.php");

use OpenEMR\Common\Acl\AclMain;

if (!AclMain::aclCheckCore('encounters', 'notes') && !AclMain::aclCheckCore('patients', 'med')) {
    echo xlt('Access denied');
    exit;
}

$pid = (int)($_GET['pid'] ?? $_SESSION['pid'] ?? 0);
$encounter = (int)($_GET['encounter'] ?? $_SESSION['encounter'] ?? 0);

// Set the active patient/encounter (needed when launched from the triage queue, where no chart is open).
if ($pid > 0) {
    require_once($GLOBALS['srcdir'] . "/pid.inc.php");
    setpid($pid);
}
if ($encounter > 0) {
    $_SESSION['encounter'] = $encounter;
}

$webroot = $GLOBALS['webroot'];
$target = $webroot . '/interface/patient_file/encounter/load_form.php?formname=vitals'; // new
if ($encounter > 0) {
    $row = sqlQuery(
        "SELECT form_id FROM forms WHERE encounter = ? AND formdir = 'vitals' AND deleted = 0
         ORDER BY form_id DESC LIMIT 1",
        [$encounter]
    );
    if (!empty($row['form_id'])) {
        $target = $webroot . '/interface/patient_file/encounter/view_form.php?formname=vitals&id=' . (int)$row['form_id'];
    }
}

header('Location: ' . $target);
exit;
