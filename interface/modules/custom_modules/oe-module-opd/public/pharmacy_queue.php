<?php

/**
 * OPD Workflow — Pharmacy worklist (confirm dispensable → dispense after payment).
 *
 * Lists active-visit prescriptions. The pharmacist:
 *   • CONFIRMS a dispensable prescription (sets/accepts the fee) → creates the cashier charge,
 *   • DISPENSES once the cashier has collected it → deducts stock (FEFO).
 * Confirming before billing, and dispensing only after payment, is enforced by OpdPharmacyService.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

require_once(__DIR__ . "/../../../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Modules\OpdWorkflow\Service\OpdPharmacyService;

// Pharmacy = in-house dispensary.
if (!AclMain::aclCheckCore('admin', 'drugs')) {
    echo xlt('Access denied');
    exit;
}

$pharmacy = new OpdPharmacyService();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'confirm') {
            $rxId = (int)($_POST['rx_id'] ?? 0);
            $fee = (float)($_POST['fee'] ?? 0);
            $pharmacy->confirmPrescription($rxId, $fee);
            $message = xl('Medication confirmed and billed — patient may pay at the cashier.');
        } elseif ($action === 'dispense') {
            $saleId = (int)($_POST['sale_id'] ?? 0);
            $pharmacy->dispense($saleId);
            $message = xl('Medication dispensed — stock updated.');
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$rows = $pharmacy->getQueue();
$csrf = CsrfUtils::collectCsrfToken();
$self = $_SERVER['PHP_SELF'];

$badge = [
    'to_confirm' => ['bg-warning text-dark', xl('To confirm')],
    'awaiting_payment' => ['bg-secondary', xl('Awaiting payment')],
    'ready' => ['bg-info', xl('Paid — ready to dispense')],
    'dispensed' => ['bg-success', xl('Dispensed')],
    'manual' => ['bg-light text-dark', xl('Free-text — handle manually')],
];
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Pharmacy Worklist'); ?></title>
    <?php Header::setupHeader(['common']); ?>
</head>
<body class="body_top container-fluid mt-3">
    <h2><?php echo xlt('Pharmacy Worklist'); ?></h2>
    <p class="text-muted"><?php echo xlt('Confirm dispensable medicines (creates the cashier charge); dispense only after the patient has paid.'); ?></p>
    <?php if ($message) { ?><div class="alert alert-success"><?php echo text($message); ?></div><?php } ?>
    <?php if ($error) { ?><div class="alert alert-danger"><?php echo text($error); ?></div><?php } ?>

    <table class="table table-sm table-bordered align-middle">
        <thead><tr>
            <th><?php echo xlt('Patient'); ?></th>
            <th><?php echo xlt('Medication'); ?></th>
            <th class="text-end"><?php echo xlt('Qty'); ?></th>
            <th class="text-end"><?php echo xlt('In stock'); ?></th>
            <th><?php echo xlt('Status'); ?></th>
            <th><?php echo xlt('Action'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r) {
            [$badgeClass, $badgeText] = $badge[$r['status']] ?? ['bg-light text-dark', $r['status']];
        ?>
            <tr>
                <td><?php echo text($r['patient']); ?> <small class="text-muted">(<?php echo text($r['pubpid']); ?>)</small></td>
                <td><?php echo text($r['drug_name']); ?></td>
                <td class="text-end"><?php echo text((string)$r['quantity']); ?></td>
                <td class="text-end <?php echo ($r['drug_id'] > 0 && $r['on_hand'] < $r['quantity']) ? 'text-danger' : ''; ?>">
                    <?php echo $r['drug_id'] > 0 ? text((string)$r['on_hand']) : '—'; ?>
                </td>
                <td><span class="badge <?php echo attr($badgeClass); ?>"><?php echo text($badgeText); ?></span></td>
                <td>
                    <?php if ($r['status'] === 'to_confirm') { ?>
                        <form method="post" action="<?php echo attr($self); ?>" class="form-inline">
                            <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                            <input type="hidden" name="action" value="confirm">
                            <input type="hidden" name="rx_id" value="<?php echo attr((string)$r['rx_id']); ?>">
                            <label class="mr-1 small"><?php echo xlt('Fee (TSh)'); ?></label>
                            <input type="number" step="1" min="0" name="fee" class="form-control form-control-sm mr-1"
                                   style="width:7rem" value="<?php echo attr(number_format((float)$r['fee'], 0, '.', '')); ?>" required>
                            <button class="btn btn-sm btn-primary" type="submit"
                                <?php echo ($r['on_hand'] < $r['quantity']) ? 'disabled title="' . attr(xl('Not enough stock')) . '"' : ''; ?>>
                                <?php echo xlt('Confirm & bill'); ?>
                            </button>
                        </form>
                    <?php } elseif ($r['status'] === 'ready') { ?>
                        <form method="post" action="<?php echo attr($self); ?>" class="d-inline">
                            <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                            <input type="hidden" name="action" value="dispense">
                            <input type="hidden" name="sale_id" value="<?php echo attr((string)$r['sale_id']); ?>">
                            <button class="btn btn-sm btn-success" type="submit"
                                <?php echo ($r['on_hand'] < $r['quantity']) ? 'disabled title="' . attr(xl('Not enough stock')) . '"' : ''; ?>>
                                <?php echo xlt('Dispense'); ?>
                            </button>
                        </form>
                    <?php } elseif ($r['status'] === 'awaiting_payment') { ?>
                        <span class="text-muted"><?php echo xlt('Patient to pay at cashier'); ?></span>
                    <?php } elseif ($r['status'] === 'dispensed') { ?>
                        <span class="text-success">&check; <?php echo xlt('Done'); ?></span>
                    <?php } else { ?>
                        <span class="text-muted"><?php echo xlt('No catalogue drug linked'); ?></span>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        <?php if (empty($rows)) { ?>
            <tr><td colspan="6"><?php echo xlt('No prescriptions awaiting the pharmacy.'); ?></td></tr>
        <?php } ?>
        </tbody>
    </table>
</body>
</html>
