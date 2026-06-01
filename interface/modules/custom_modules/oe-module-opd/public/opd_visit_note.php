<?php

/**
 * OPD Workflow — "OPD Visit Note" opener (one note per encounter).
 *
 * The encounter "Add form" menu always creates a NEW blank LBF instance, so clicking
 * "OPD Visit Note" again made the doctor's saved note look lost (it was a separate instance).
 * This opener instead reopens the EXISTING OPD Visit Note for the encounter (the instance with
 * the most data) for editing, and only creates a new one when none exists yet.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

require_once(__DIR__ . "/../../../../globals.php");

use OpenEMR\Common\Acl\AclMain;

if (!AclMain::aclCheckCore('encounters', 'notes') && !AclMain::aclCheckCore('patients', 'rx')) {
    echo xlt('Access denied');
    exit;
}

$encounter = (int)($_GET['encounter'] ?? $_SESSION['encounter'] ?? 0);
$webroot = $GLOBALS['webroot'];

$target = $webroot . '/interface/patient_file/encounter/load_form.php?formname=LBFopd_visit'; // new
if ($encounter > 0) {
    // Prefer the existing instance carrying the most field data.
    $row = sqlQuery(
        "SELECT f.form_id,
                (SELECT COUNT(*) FROM lbf_data d WHERE d.form_id = f.form_id) AS ndata
         FROM forms f
         WHERE f.encounter = ? AND f.formdir = 'LBFopd_visit' AND f.deleted = 0
         ORDER BY ndata DESC, f.form_id DESC LIMIT 1",
        [$encounter]
    );
    if (!empty($row['form_id'])) {
        $target = $webroot . '/interface/patient_file/encounter/view_form.php?formname=LBFopd_visit&id=' . (int)$row['form_id'];
    }
}

header('Location: ' . $target);
exit;
