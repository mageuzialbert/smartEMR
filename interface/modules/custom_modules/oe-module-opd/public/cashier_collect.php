<?php

/**
 * OPD Workflow — Cashier per-line collection.
 *
 * Lists each charge line on a visit (consultation, registration, lab/radiology/procedure orders,
 * dispensed products) with its own fee / paid / balance, and lets the cashier collect PER LINE.
 * Each collected line posts a payment allocated to that charge's code (OpdGatingService::recordLinePayment),
 * so paying a specific order clears exactly that order — which unlocks its department to post results
 * (OpdOrderBillingService::isOrderPaid is per-code exact). When the visit's reg+consult are cleared the
 * visit advances RG -> PD (activateIfPaid).
 *
 * Reuses the per-line assembly from encounter_bill_view.php (getBillingByEncounter + arGetInvoiceSummary
 * + drug_sales) so the figures match the read-only Patient Bill and the native A/R screens.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

require_once(__DIR__ . "/../../../../globals.php");

use OpenEMR\Billing\BillingUtilities;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Modules\OpdWorkflow\Service\OpdGatingService;

// Cashier = Accounting: needs billing access.
if (!AclMain::aclCheckCore('acct', 'bill') && !AclMain::aclCheckCore('acct', 'rep')) {
    echo xlt('Access denied');
    exit;
}

$gating = new OpdGatingService();
$message = '';
$error = '';

$pid = (int)($_GET['pid'] ?? $_POST['pid'] ?? 0);
$encounter = (int)($_GET['encounter'] ?? $_POST['encounter'] ?? 0);

// If only a pid is given (e.g. from the patient menu), default to the latest encounter.
if ($pid > 0 && $encounter <= 0) {
    $latest = sqlQuery("SELECT encounter FROM form_encounter WHERE pid = ? ORDER BY date DESC, encounter DESC LIMIT 1", [$pid]);
    $encounter = (int)($latest['encounter'] ?? 0);
}

/**
 * Build the per-line charge/paid/balance rows for an encounter.
 * Mirrors oe-module-opd/public/encounter_bill_view.php.
 * @return array<int,array{code_type:string,code:string,modifier:string,label:string,chg:float,paid:float,bal:float}>
 */
function opdBillLines(int $pid, int $encounter): array
{
    $out = [];
    if (!$pid || !$encounter) {
        return $out;
    }
    $lines = BillingUtilities::getBillingByEncounter($pid, $encounter, "code_type, code, modifier, code_text, fee") ?: [];

    $sres = sqlStatement(
        "SELECT s.drug_id, s.fee, d.name FROM drug_sales AS s
         LEFT JOIN drugs AS d ON d.drug_id = s.drug_id
         WHERE s.pid = ? AND s.encounter = ? ORDER BY s.sale_id",
        [$pid, $encounter]
    );
    while ($srow = sqlFetchArray($sres)) {
        $lines[] = ['code_type' => 'PROD', 'code' => 'PROD:' . $srow['drug_id'],
            'modifier' => '', 'code_text' => $srow['name'], 'fee' => $srow['fee']];
    }

    $inv = OpdGatingService::invoiceSummary($pid, $encounter);
    foreach ($lines as $ln) {
        $codekey = $ln['code'];
        if (!empty($ln['modifier'])) {
            $codekey .= ':' . $ln['modifier'];
        }
        $label = ($ln['code_text'] ?? '') !== '' ? $ln['code_text'] : (($ln['code_type'] ?? '') . ' ' . ($ln['code'] ?? ''));
        if (empty($inv[$codekey])) {
            $chg = (float)($ln['fee'] ?? 0);
            $paid = 0.0;
            $bal = $chg;
        } else {
            $i = $inv[$codekey];
            $chg = (float)$i['chg'] + (float)($i['adj'] ?? 0);
            $bal = (float)$i['bal'];
            $paid = $chg - $bal;
        }
        $out[] = [
            'code_type' => (string)($ln['code_type'] ?? ''),
            'code' => (string)($ln['code'] ?? ''),
            'modifier' => (string)($ln['modifier'] ?? ''),
            'label' => (string)$label,
            'chg' => $chg, 'paid' => $paid, 'bal' => $bal,
        ];
    }
    return $out;
}

// ── Handle collect POST ─────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    $method = trim($_POST['method'] ?? '');
    $eid = (int)($_POST['eid'] ?? 0);
    $sel = $_POST['collect'] ?? [];          // code(:mod) => '1'
    $amt = $_POST['amount'] ?? [];           // code(:mod) => amount
    try {
        if ($pid <= 0 || $encounter <= 0 || $method === '') {
            throw new \RuntimeException(xl('Missing payment details.'));
        }
        $byKey = [];
        foreach (opdBillLines($pid, $encounter) as $ln) {
            $k = $ln['code'] . ($ln['modifier'] !== '' ? ':' . $ln['modifier'] : '');
            $byKey[$k] = $ln;
        }
        $allocations = [];
        foreach ($sel as $key => $on) {
            if (empty($on) || empty($byKey[$key])) {
                continue;
            }
            $ln = $byKey[$key];
            $pay = isset($amt[$key]) && $amt[$key] !== '' ? (float)$amt[$key] : $ln['bal'];
            $pay = min($pay, $ln['bal']);     // never overpay a line
            if ($pay > 0) {
                $allocations[] = ['code_type' => $ln['code_type'], 'code' => $ln['code'],
                    'modifier' => $ln['modifier'], 'amount' => $pay];
            }
        }
        if (empty($allocations)) {
            throw new \RuntimeException(xl('Select at least one line to collect.'));
        }
        $total = array_sum(array_column($allocations, 'amount'));
        $gating->recordLinePayment($pid, $encounter, $allocations, $method, 'OPD cashier');
        if ($eid > 0) {
            $gating->activateIfPaid($eid, $pid, $encounter);
        }
        $message = xl('Collected') . ' — ' . xl('TSh') . ' ' . number_format($total, 0)
            . ' ' . xl('across') . ' ' . count($allocations) . ' ' . xl('item(s).');
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Render ──────────────────────────────────────────────────────────────────
$pat = $pid ? sqlQuery("SELECT fname, mname, lname, pubpid FROM patient_data WHERE pid = ?", [$pid]) : null;
$patientName = $pat ? trim(($pat['fname'] ?? '') . ' ' . ($pat['lname'] ?? '')) : '';
$eidRow = $encounter ? sqlQuery(
    "SELECT pc_eid FROM openemr_postcalendar_events e
     JOIN form_encounter fe ON fe.pid = e.pc_pid AND fe.date = CONCAT(e.pc_eventDate, ' 00:00:00')
     WHERE fe.encounter = ? ORDER BY e.pc_eid DESC LIMIT 1",
    [$encounter]
) : null;
$eid = (int)($eidRow['pc_eid'] ?? 0);

$rows = opdBillLines($pid, $encounter);
$tot = ['chg' => 0.0, 'paid' => 0.0, 'bal' => 0.0];
foreach ($rows as $r) {
    $tot['chg'] += $r['chg'];
    $tot['paid'] += $r['paid'];
    $tot['bal'] += $r['bal'];
}

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
    <title><?php echo xlt('Collect Payment'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <script>
        // Default each amount box to its line balance; toggle disable with the checkbox.
        function opdToggle(key) {
            var cb = document.getElementById('cb_' + key);
            var amt = document.getElementById('amt_' + key);
            if (amt) { amt.disabled = !cb.checked; }
        }
    </script>
</head>
<body class="body_top container-fluid mt-3">
    <h2><?php echo xlt('Collect Payment'); ?></h2>
    <p class="text-muted">
        <?php echo text($patientName); ?>
        <?php if ($pat) { ?>(<?php echo text($pat['pubpid']); ?>)<?php } ?>
        <?php if ($encounter) { ?> — <?php echo xlt('Encounter'); ?> <?php echo text((string)$encounter); ?><?php } ?>
    </p>
    <?php if ($message) { ?><div class="alert alert-success"><?php echo text($message); ?></div><?php } ?>
    <?php if ($error) { ?><div class="alert alert-danger"><?php echo text($error); ?></div><?php } ?>

    <?php if (empty($rows)) { ?>
        <div class="alert alert-light border"><?php echo xlt('No charges recorded for this visit.'); ?></div>
    <?php } else { ?>
    <form method="post" action="<?php echo attr($self . '?pid=' . $pid . '&encounter=' . $encounter); ?>">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
        <input type="hidden" name="pid" value="<?php echo attr((string)$pid); ?>">
        <input type="hidden" name="encounter" value="<?php echo attr((string)$encounter); ?>">
        <input type="hidden" name="eid" value="<?php echo attr((string)$eid); ?>">
        <table class="table table-sm table-bordered align-middle w-auto">
            <thead class="thead-light">
                <tr>
                    <th><?php echo xlt('Collect'); ?></th>
                    <th><?php echo xlt('Item'); ?></th>
                    <th class="text-end"><?php echo xlt('Charge'); ?></th>
                    <th class="text-end"><?php echo xlt('Paid'); ?></th>
                    <th class="text-end"><?php echo xlt('Balance'); ?></th>
                    <th class="text-end"><?php echo xlt('Amount to collect'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r) {
                $key = $r['code'] . ($r['modifier'] !== '' ? ':' . $r['modifier'] : '');
                $keyAttr = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
                $paid = $r['bal'] <= OpdGatingService::PAID_EPSILON;
            ?>
                <tr>
                    <td class="text-center">
                        <?php if ($paid) { ?>
                            <span class="text-success"><?php echo xlt('Paid'); ?> &check;</span>
                        <?php } else { ?>
                            <input type="checkbox" id="cb_<?php echo attr($keyAttr); ?>"
                                   name="collect[<?php echo attr($key); ?>]" value="1" checked
                                   onclick="opdToggle('<?php echo attr_js($keyAttr); ?>')">
                        <?php } ?>
                    </td>
                    <td><?php echo text($r['label']); ?> <small class="text-muted">(<?php echo text($r['code']); ?>)</small></td>
                    <td class="text-end"><?php echo text(number_format($r['chg'], 0)); ?></td>
                    <td class="text-end"><?php echo text(number_format($r['paid'], 0)); ?></td>
                    <td class="text-end <?php echo $paid ? 'text-success' : 'text-danger'; ?>"><?php echo text(number_format($r['bal'], 0)); ?></td>
                    <td class="text-end">
                        <?php if (!$paid) { ?>
                            <input type="number" step="1" min="0" max="<?php echo attr(number_format($r['bal'], 0, '.', '')); ?>"
                                   id="amt_<?php echo attr($keyAttr); ?>"
                                   name="amount[<?php echo attr($key); ?>]" class="form-control form-control-sm text-end"
                                   style="width:8rem; display:inline-block"
                                   value="<?php echo attr(number_format($r['bal'], 0, '.', '')); ?>">
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
            <tfoot>
                <tr class="font-weight-bold">
                    <td colspan="2"><?php echo xlt('Total'); ?></td>
                    <td class="text-end"><?php echo text(number_format($tot['chg'], 0)); ?></td>
                    <td class="text-end"><?php echo text(number_format($tot['paid'], 0)); ?></td>
                    <td class="text-end"><?php echo text(number_format($tot['bal'], 0)); ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <?php if ($tot['bal'] > OpdGatingService::PAID_EPSILON) { ?>
            <div class="form-inline">
                <label class="mr-2"><?php echo xlt('Payment method'); ?>:</label>
                <select name="method" class="form-control mr-2" required>
                    <?php foreach ($methods as $m) { ?>
                        <option value="<?php echo attr($m['option_id']); ?>"><?php echo text($m['title']); ?></option>
                    <?php } ?>
                </select>
                <button class="btn btn-primary" type="submit"><?php echo xlt('Collect selected'); ?></button>
            </div>
        <?php } else { ?>
            <div class="alert alert-success w-auto d-inline-block"><?php echo xlt('Fully paid.'); ?></div>
        <?php } ?>
    </form>
    <?php } ?>
</body>
</html>
