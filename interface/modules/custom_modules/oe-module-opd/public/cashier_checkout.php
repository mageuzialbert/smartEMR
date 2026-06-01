<?php

/**
 * OPD Workflow — Cashier worklist.
 *
 * Lists today's patients awaiting the cashier (RG) and just paid (PD) with their
 * Registration + Consultation charges and outstanding balance. The cashier records
 * a payment (method + amount); when the balance reaches zero the visit advances to
 * PD (Paid / Awaiting Triage) and becomes visible to the nurse. Already-paid RG
 * visits are auto-advanced on load (e.g. paid via the native Checkout screen).
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

// Cashier = Accounting: needs billing access.
if (!AclMain::aclCheckCore('acct', 'bill') && !AclMain::aclCheckCore('acct', 'rep')) {
    echo xlt('Access denied');
    exit;
}

$gating = new OpdGatingService();
$message = '';
$error = '';

// ── Handle payment POST ────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    $pid = (int)($_POST['pid'] ?? 0);
    $eid = (int)($_POST['eid'] ?? 0);
    $enc = (int)($_POST['encounter'] ?? 0);
    $method = trim($_POST['method'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    try {
        if ($pid <= 0 || $enc <= 0 || $method === '') {
            throw new \RuntimeException(xl('Missing payment details.'));
        }
        $gating->recordPayment($pid, $enc, $method, $amount, 'OPD cashier');
        $paid = $gating->activateIfPaid($eid, $pid, $enc);
        $message = xl('Payment recorded') . ' — ' . xl('TSh') . ' ' . number_format($amount, 0)
            . '. ' . ($paid ? xl('Visit fully paid — sent to triage.') : xl('Balance remaining; not yet sent to triage.'));
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Cashier worklist: active visits with money to collect ───────────────────
// Broadened from RG/PD-only so charges added AFTER triage (lab/radiology/procedure orders,
// dispensed meds) are collectible too. We show a visit if it is still awaiting the cashier (RG)
// or has any outstanding balance.
$rows = [];
$statuses = OpdVisitService::ACTIVE_STATUSES;
$ph = implode(',', array_fill(0, count($statuses), '?'));
$res = sqlStatement(
    "SELECT e.pc_eid, e.pc_pid, e.pc_apptstatus, e.pc_startTime,
            cat.pc_catname, pd.fname, pd.lname, pd.pubpid,
            fe.encounter
     FROM openemr_postcalendar_events e
     JOIN openemr_postcalendar_categories cat ON cat.pc_catid = e.pc_catid AND cat.pc_constant_id LIKE 'clinic_%'
     JOIN patient_data pd ON pd.pid = e.pc_pid
     LEFT JOIN form_encounter fe ON fe.pid = e.pc_pid AND fe.date = CONCAT(e.pc_eventDate, ' 00:00:00')
     WHERE e.pc_eventDate >= (CURDATE() - INTERVAL ? DAY) AND e.pc_apptstatus IN ($ph) AND e.pc_pid > 0
     ORDER BY FIELD(e.pc_apptstatus,'RG','PD'), e.pc_eventDate, e.pc_startTime",
    array_merge([OpdVisitService::ACTIVE_LOOKBACK_DAYS], $statuses)
);
while ($r = sqlFetchArray($res)) {
    $enc = (int)$r['encounter'];
    $balance = $enc ? $gating->getVisitBalance((int)$r['pc_pid'], $enc) : 0.0;
    // Auto-advance: an RG visit already fully paid (e.g. via native Checkout) → PD.
    if ($enc && $r['pc_apptstatus'] === 'RG' && $balance <= OpdGatingService::PAID_EPSILON) {
        $gating->activateIfPaid((int)$r['pc_eid'], (int)$r['pc_pid'], $enc);
        $r['pc_apptstatus'] = 'PD';
    }
    // Only list visits awaiting the cashier (RG) or with an outstanding balance.
    if ($r['pc_apptstatus'] !== 'RG' && $balance <= OpdGatingService::PAID_EPSILON) {
        continue;
    }
    $r['balance'] = $balance;
    $r['charges'] = $enc ? $gating->getEncounterCharges($enc) : [];
    $rows[] = $r;
}

// Payment methods
$methods = [];
$mres = sqlStatement("SELECT option_id, title FROM list_options WHERE list_id = 'payment_method' AND activity = 1 ORDER BY seq, title");
while ($m = sqlFetchArray($mres)) {
    $methods[] = $m;
}

$csrf = CsrfUtils::collectCsrfToken();
$self = $_SERVER['PHP_SELF'];
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('OPD Cashier'); ?></title>
    <?php Header::setupHeader(['common']); ?>
</head>
<body class="body_top container-fluid mt-3">
    <h2><?php echo xlt('Cashier — Today\'s Visits'); ?></h2>
    <?php if ($message) { ?><div class="alert alert-success"><?php echo text($message); ?></div><?php } ?>
    <?php if ($error) { ?><div class="alert alert-danger"><?php echo text($error); ?></div><?php } ?>

    <table class="table table-sm table-bordered align-middle">
        <thead><tr>
            <th><?php echo xlt('Patient'); ?></th>
            <th><?php echo xlt('Reg No.'); ?></th>
            <th><?php echo xlt('Clinic'); ?></th>
            <th><?php echo xlt('Charges'); ?></th>
            <th class="text-end"><?php echo xlt('Balance (TSh)'); ?></th>
            <th><?php echo xlt('Status'); ?></th>
            <th><?php echo xlt('Collect payment'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r) {
            $paid = $r['balance'] <= OpdGatingService::PAID_EPSILON;
            $statusLabel = $r['pc_apptstatus'] === 'PD' ? xl('Paid — Awaiting Triage') : xl('Registered — Awaiting Cashier');
        ?>
            <tr>
                <td><?php echo text($r['lname'] . ', ' . $r['fname']); ?></td>
                <td><?php echo text($r['pubpid']); ?></td>
                <td><?php echo text($r['pc_catname']); ?></td>
                <td><small><?php foreach ($r['charges'] as $c) {
                    echo text($c['code'] . ': ' . number_format($c['fee'], 0)) . '<br>';
                } ?></small></td>
                <td class="text-end <?php echo $paid ? 'text-success' : 'text-danger'; ?>"><?php echo text(number_format($r['balance'], 0)); ?></td>
                <td><span class="badge <?php echo $r['pc_apptstatus'] === 'PD' ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo text($statusLabel); ?></span></td>
                <td>
                    <?php if ($paid) { ?>
                        <span class="text-muted"><?php echo xlt('Fully paid'); ?></span>
                    <?php } else {
                        $collectUrl = $GLOBALS['webroot'] . '/interface/modules/custom_modules/oe-module-opd/public/cashier_collect.php'
                            . '?pid=' . (int)$r['pc_pid'] . '&encounter=' . (int)$r['encounter'];
                    ?>
                        <a class="btn btn-sm btn-primary" href="<?php echo attr($collectUrl); ?>"><?php echo xlt('Open / Collect'); ?></a>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        <?php if (empty($rows)) { ?>
            <tr><td colspan="7"><?php echo xlt('No patients awaiting the cashier today.'); ?></td></tr>
        <?php } ?>
        </tbody>
    </table>
    <p class="text-muted"><?php echo xlt('Paid patients (PD) move to the nurse\'s triage queue.'); ?></p>
</body>
</html>
