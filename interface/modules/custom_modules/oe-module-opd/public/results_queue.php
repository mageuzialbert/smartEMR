<?php

/**
 * OPD Workflow — departmental results worklist.
 *
 * One page, role-derived: the department(s) a user may result come from their ACL role
 * (OpdOrderBillingService::allowedDepartments) — Lab Technicians → Clinic Laboratory,
 * Radiologists → Clinic Radiology, Physicians → Clinic Procedures (minor theatre). It lists
 * that department's recent procedure orders with a paid/unpaid badge and an "Enter results"
 * action that is enabled ONLY when the order's bill is cleared at the cashier (per-order
 * waterfall). The native result screen (orders_results.php) re-checks both as the hard backstop.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

require_once(__DIR__ . "/../../../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;
use OpenEMR\Modules\OpdWorkflow\Service\OpdOrderBillingService;
use OpenEMR\Modules\OpdWorkflow\Service\OpdVisitService;

// Must be able to sign/enter results at all.
if (!AclMain::aclCheckCore('patients', 'sign')) {
    echo xlt('Access denied');
    exit;
}

$billing = new OpdOrderBillingService();
$depts = $billing->allowedDepartments();
if (empty($depts)) {
    echo xlt('Your role is not assigned to any results department.');
    exit;
}

// Recent orders for this user's department(s).
$placeholders = implode(',', array_fill(0, count($depts), '?'));
$binds = array_merge($depts, [OpdVisitService::ACTIVE_LOOKBACK_DAYS]);
$rows = [];
$res = sqlStatement(
    "SELECT po.procedure_order_id, po.patient_id, po.encounter_id, po.lab_id, po.date_ordered,
            pd.fname, pd.lname, pd.pubpid,
            pp.name AS dept_name,
            (SELECT GROUP_CONCAT(poc.procedure_name SEPARATOR ', ')
               FROM procedure_order_code poc WHERE poc.procedure_order_id = po.procedure_order_id) AS items,
            (SELECT COUNT(*) FROM procedure_report pr
               WHERE pr.procedure_order_id = po.procedure_order_id) AS report_count
     FROM procedure_order po
     JOIN patient_data pd ON pd.pid = po.patient_id
     JOIN procedure_providers pp ON pp.ppid = po.lab_id
     WHERE po.lab_id IN ($placeholders)
           AND po.activity = 1
           AND po.date_ordered >= (CURDATE() - INTERVAL ? DAY)
     ORDER BY po.date_ordered DESC, po.procedure_order_id DESC",
    $binds
);
while ($r = sqlFetchArray($res)) {
    $r['paid'] = $billing->isOrderPaid((int)$r['procedure_order_id']);
    $rows[] = $r;
}

$webroot = $GLOBALS['webroot'];
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Results Worklist'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <script>
        // Set the patient as current and open the result-entry screen for that patient.
        function toresults(pid) {
            if (top.restoreSession) { top.restoreSession(); }
            top.RTop.location = <?php echo js_escape($webroot); ?> +
                "/interface/orders/orders_results.php?set_pid=" + encodeURIComponent(pid);
            return false;
        }
    </script>
</head>
<body class="body_top container-fluid mt-3">
    <h2><?php echo xlt('Results Worklist'); ?></h2>
    <p class="text-muted">
        <?php echo xlt('Orders awaiting results. You may enter results only after the patient has paid for the order at the cashier.'); ?>
    </p>

    <table class="table table-sm table-bordered align-middle">
        <thead><tr>
            <th><?php echo xlt('Patient'); ?></th>
            <th><?php echo xlt('Reg No.'); ?></th>
            <th><?php echo xlt('Department'); ?></th>
            <th><?php echo xlt('Order'); ?></th>
            <th><?php echo xlt('Ordered'); ?></th>
            <th><?php echo xlt('Billing'); ?></th>
            <th><?php echo xlt('Action'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r) { ?>
            <tr>
                <td><?php echo text($r['lname'] . ', ' . $r['fname']); ?></td>
                <td><?php echo text($r['pubpid']); ?></td>
                <td><?php echo text($r['dept_name']); ?></td>
                <td><?php echo text($r['items'] ?? ''); ?></td>
                <td><?php echo text(substr((string)$r['date_ordered'], 0, 10)); ?></td>
                <td>
                    <?php if ($r['paid']) { ?>
                        <span class="badge bg-success"><?php echo xlt('Paid'); ?></span>
                    <?php } else { ?>
                        <span class="badge bg-warning text-dark"><?php echo xlt('Awaiting payment'); ?></span>
                    <?php } ?>
                    <?php if ((int)$r['report_count'] > 0) { ?>
                        <span class="badge bg-info"><?php echo xlt('Results entered'); ?></span>
                    <?php } ?>
                </td>
                <td>
                    <?php if ($r['paid']) { ?>
                        <button type="button" class="btn btn-sm btn-success"
                                onclick="return toresults(<?php echo attr_js($r['patient_id']); ?>);">
                            <?php echo ((int)$r['report_count'] > 0) ? xlt('View / edit results') : xlt('Enter results'); ?>
                        </button>
                    <?php } else { ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                title="<?php echo xla('Patient must clear the bill at the cashier first'); ?>">
                            <?php echo xlt('Awaiting cashier'); ?>
                        </button>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        <?php if (empty($rows)) { ?>
            <tr><td colspan="7"><?php echo xlt('No orders for your department.'); ?></td></tr>
        <?php } ?>
        </tbody>
    </table>
</body>
</html>
