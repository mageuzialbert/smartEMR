<?php

/**
 * OPD Workflow — read-only encounter bill.
 *
 * Shown to clinicians (who must NOT bill) from the encounter "Administrative" menu
 * in place of the editable Fee Sheet. Renders the current visit's charges,
 * payments, adjustments and balance — same numbers as the "Visit History" billing
 * view (interface/patient_file/history/encounters.php) — but entirely read-only:
 * no edit links, no "+ Add", no fee-sheet access. Billing itself stays with the
 * cashier (who keeps the editable Fee Sheet).
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

require_once(__DIR__ . "/../../../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;
use OpenEMR\Billing\BillingUtilities;
use OpenEMR\Billing\InvoiceSummary;

// Read access: clinicians (coding/notes) or accounting (rep/bill). Not a billing screen.
if (
    !AclMain::aclCheckCore('encounters', 'coding')
    && !AclMain::aclCheckCore('encounters', 'notes')
    && !AclMain::aclCheckCore('acct', 'rep')
    && !AclMain::aclCheckCore('acct', 'bill')
) {
    echo (new OpenEMR\Common\Twig\TwigContainer(null, $GLOBALS['kernel']))->getTwig()
        ->render('core/unauthorized.html.twig', ['pageTitle' => xl('Patient Bill')]);
    exit;
}

$pid = (int)($_GET['pid'] ?? $_SESSION['pid'] ?? 0);
$encounter = (int)($_GET['encounter'] ?? $_SESSION['encounter'] ?? 0);

$enc = $pid && $encounter
    ? sqlQuery("SELECT date FROM form_encounter WHERE pid = ? AND encounter = ?", [$pid, $encounter])
    : null;
$pat = $pid ? sqlQuery("SELECT fname, mname, lname FROM patient_data WHERE pid = ?", [$pid]) : null;
$patientName = $pat ? trim(($pat['fname'] ?? '') . ' ' . ($pat['mname'] ?? '') . ' ' . ($pat['lname'] ?? '')) : '';

// --- Assemble billing lines (mirrors encounters.php billing view) ---
$rows = [];          // each: [label, chg, paid, adj, bal]
$tot = ['chg' => 0.0, 'paid' => 0.0, 'adj' => 0.0, 'bal' => 0.0];

if ($pid && $encounter) {
    $lines = BillingUtilities::getBillingByEncounter($pid, $encounter, "code_type, code, modifier, code_text, fee") ?: [];

    // Pharmacy / product sales for this encounter.
    $sres = sqlStatement(
        "SELECT s.drug_id, s.fee, d.name FROM drug_sales AS s " .
        "LEFT JOIN drugs AS d ON d.drug_id = s.drug_id " .
        "WHERE s.pid = ? AND s.encounter = ? ORDER BY s.sale_id",
        [$pid, $encounter]
    );
    while ($srow = sqlFetchArray($sres)) {
        $lines[] = ['code_type' => 'PROD', 'code' => 'PROD:' . $srow['drug_id'],
            'modifier' => '', 'code_text' => $srow['name'], 'fee' => $srow['fee']];
    }

    $arinvoice = InvoiceSummary::arGetInvoiceSummary($pid, $encounter, true);

    foreach ($lines as $ln) {
        $label = ($ln['code_type'] ?? '') . ' - ' . ($ln['code'] ?? '');
        $codekey = $ln['code'];
        if (($ln['code_type'] ?? '') == 'COPAY') {
            $codekey = 'CO-PAY';
            $label = xl('CO-PAY');
        }
        if (!empty($ln['modifier'])) {
            $codekey .= ':' . $ln['modifier'];
            $label .= ':' . $ln['modifier'];
        }
        if (($ln['code_text'] ?? '') !== '') {
            $label .= ' — ' . $ln['code_text'];
        }

        if (empty($arinvoice[$codekey])) {
            // Not yet in A/R: show the fee as the charge.
            $chg = (float)($ln['fee'] ?? 0);
            $rows[] = [$label, $chg, 0.0, 0.0, $chg];
            $tot['chg'] += $chg;
            $tot['bal'] += $chg;
        } else {
            $inv = $arinvoice[$codekey];
            $chg = (float)$inv['chg'] + (float)($inv['adj'] ?? 0);
            $paid = (float)$inv['chg'] - (float)$inv['bal'];
            $adj = (float)($inv['adj'] ?? 0);
            $bal = (float)$inv['bal'];
            $rows[] = [$label, $chg, $paid, $adj, $bal];
            $tot['chg'] += $chg;
            $tot['paid'] += $paid;
            $tot['adj'] += $adj;
            $tot['bal'] += $bal;
            unset($arinvoice[$codekey]);
        }
    }

    // Any leftover A/R items not tied to a billing line (e.g. payments/copays).
    foreach ($arinvoice as $codekey => $inv) {
        $chg = (float)$inv['chg'] + (float)($inv['adj'] ?? 0);
        $paid = (float)$inv['chg'] - (float)$inv['bal'];
        $adj = (float)($inv['adj'] ?? 0);
        $bal = (float)$inv['bal'];
        $rows[] = [text((string)$codekey), $chg, $paid, $adj, $bal];
        $tot['chg'] += $chg;
        $tot['paid'] += $paid;
        $tot['adj'] += $adj;
        $tot['bal'] += $bal;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Patient Bill'); ?></title>
    <?php Header::setupHeader(['common']); ?>
</head>
<body class="mt-3 mx-3">
    <h4 class="mb-1"><?php echo xlt('Patient Bill'); ?></h4>
    <p class="text-muted mb-3">
        <?php
        echo text($patientName);
        if (!empty($enc['date'])) {
            echo ' — ' . text(oeFormatShortDate(substr((string)$enc['date'], 0, 10)));
        }
        ?>
        <span class="badge badge-info ml-2"><?php echo xlt('View only'); ?></span>
    </p>

    <?php if (empty($rows)) { ?>
        <div class="alert alert-light border"><?php echo xlt('No charges recorded for this visit.'); ?></div>
    <?php } else { ?>
        <table class="table table-sm table-bordered w-auto">
            <thead class="thead-light">
                <tr>
                    <th><?php echo xlt('Code / Item'); ?></th>
                    <th class="text-right"><?php echo xlt('Charge'); ?></th>
                    <th class="text-right"><?php echo xlt('Paid'); ?></th>
                    <th class="text-right"><?php echo xlt('Adjustment'); ?></th>
                    <th class="text-right"><?php echo xlt('Balance'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r) { ?>
                    <tr>
                        <td><?php echo text($r[0]); ?></td>
                        <td class="text-right"><?php echo text(oeFormatMoney($r[1])); ?></td>
                        <td class="text-right"><?php echo text(oeFormatMoney($r[2])); ?></td>
                        <td class="text-right"><?php echo text(oeFormatMoney($r[3])); ?></td>
                        <td class="text-right"><?php echo text(oeFormatMoney($r[4])); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
            <tfoot>
                <tr class="font-weight-bold">
                    <td><?php echo xlt('Total'); ?></td>
                    <td class="text-right"><?php echo text(oeFormatMoney($tot['chg'])); ?></td>
                    <td class="text-right"><?php echo text(oeFormatMoney($tot['paid'])); ?></td>
                    <td class="text-right"><?php echo text(oeFormatMoney($tot['adj'])); ?></td>
                    <td class="text-right"><?php echo text(oeFormatMoney($tot['bal'])); ?></td>
                </tr>
            </tfoot>
        </table>
        <p class="small text-muted"><?php echo xlt('Billing and payments are handled at the Cashier.'); ?></p>
    <?php } ?>
</body>
</html>
