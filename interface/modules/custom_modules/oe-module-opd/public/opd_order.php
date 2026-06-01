<?php

/**
 * OPD Workflow — department order opener (one order per department per encounter).
 *
 * The Orders menu always created a NEW procedure_order per click, so a visit accumulated several
 * orders in the same department. This opener REOPENS the encounter's existing order for the chosen
 * department (so the doctor adds more procedures to it) and only creates a new one when none exists.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

require_once(__DIR__ . "/../../../../globals.php");

use OpenEMR\Common\Acl\AclMain;

if (!AclMain::aclCheckCore('patients', 'lab') && !AclMain::aclCheckCore('encounters', 'notes')) {
    echo xlt('Access denied');
    exit;
}

$labId = (int)($_GET['lab_id'] ?? 0);
$encounter = (int)($_GET['encounter'] ?? $_SESSION['encounter'] ?? 0);
$webroot = $GLOBALS['webroot'];

// Default: new order scoped to the department.
$target = $webroot . '/interface/patient_file/encounter/load_form.php?formname=procedure_order&lab_id=' . $labId;

if ($encounter > 0 && $labId > 0) {
    $row = sqlQuery(
        "SELECT po.procedure_order_id
         FROM procedure_order po
         JOIN forms f ON f.formdir = 'procedure_order' AND f.form_id = po.procedure_order_id AND f.deleted = 0
         WHERE po.encounter_id = ? AND po.lab_id = ? AND po.activity = 1
         ORDER BY po.procedure_order_id DESC LIMIT 1",
        [$encounter, $labId]
    );
    if (!empty($row['procedure_order_id'])) {
        $target = $webroot . '/interface/patient_file/encounter/view_form.php?formname=procedure_order&id='
            . (int)$row['procedure_order_id'];
    }
}

header('Location: ' . $target);
exit;
