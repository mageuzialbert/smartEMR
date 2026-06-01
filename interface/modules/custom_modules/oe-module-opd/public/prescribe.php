<?php

/**
 * OPD Workflow — fast multi-row prescription entry.
 *
 * One grid row per drug: Drug · Dose · Unit · Frequency · Days → Quantity auto-calculated
 * (dose × per-day × days; PRN entered manually). Saves standard `prescriptions` rows that feed the
 * pharmacy worklist; stock is deducted later at dispense (after payment). Replaces the native
 * one-drug-per-page Rx form for the OPD doctor.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

require_once(__DIR__ . "/../../../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Modules\OpdWorkflow\Service\OpdPrescribeService;

if (!AclMain::aclCheckCore('patients', 'rx')) {
    echo xlt('Access denied');
    exit;
}

$svc = new OpdPrescribeService();
$message = '';
$error = '';

$pid = (int)($_GET['pid'] ?? $_POST['pid'] ?? $_SESSION['pid'] ?? 0);
$encounter = (int)($_GET['encounter'] ?? $_POST['encounter'] ?? $_SESSION['encounter'] ?? 0);
if ($pid > 0 && $encounter <= 0) {
    $latest = sqlQuery("SELECT encounter FROM form_encounter WHERE pid = ? ORDER BY date DESC, encounter DESC LIMIT 1", [$pid]);
    $encounter = (int)($latest['encounter'] ?? 0);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    try {
        if (($_POST['action'] ?? '') === 'remove') {
            $svc->archive((int)($_POST['rx_id'] ?? 0));
            $message = xl('Medicine removed.');
        } elseif (($_POST['action'] ?? '') === 'save') {
            if ($pid <= 0 || $encounter <= 0) {
                throw new \RuntimeException(xl('No open encounter for this patient.'));
            }
            $rows = [];
            $ids = $_POST['drug_id'] ?? [];
            foreach ($ids as $i => $drugId) {
                $rows[] = [
                    'drug_id' => (int)$drugId,
                    'dose' => $_POST['dose'][$i] ?? '',
                    'unit' => $_POST['unit'][$i] ?? '',
                    'freq' => $_POST['freq'][$i] ?? '',
                    'days' => $_POST['days'][$i] ?? '',
                    'qty' => $_POST['qty'][$i] ?? '',
                ];
            }
            $n = $svc->save($pid, $encounter, (int)($_SESSION['authUserID'] ?? 0), $rows);
            $message = $n > 0
                ? ($n . ' ' . xl('prescription(s) saved — sent to pharmacy.'))
                : xl('Nothing saved — pick a drug from the list and set the details.');
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$catalogue = $svc->drugCatalogue();
$units = $svc->unitOptions();
$existing = ($pid && $encounter) ? $svc->listForEncounter($pid, $encounter) : [];

// name -> {id, form} for the datalist resolver.
$drugMap = [];
foreach ($catalogue as $d) {
    $drugMap[$d['name']] = ['id' => $d['drug_id'], 'form' => (string)$d['form']];
}

$pat = $pid ? sqlQuery("SELECT fname, lname, pubpid FROM patient_data WHERE pid = ?", [$pid]) : null;
$csrf = CsrfUtils::collectCsrfToken();
$self = $_SERVER['PHP_SELF'];
$qs = '?pid=' . $pid . '&encounter=' . $encounter;
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Prescribe'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        #rxgrid td { vertical-align: middle; }
        #rxgrid input, #rxgrid select { min-width: 5rem; }
        .rx-drug { min-width: 18rem !important; }
        .rx-qty { background: #eef; font-weight: bold; }
    </style>
</head>
<body class="body_top container-fluid mt-3">
    <h2><?php echo xlt('Prescribe'); ?></h2>
    <p class="text-muted">
        <?php if ($pat) { echo text($pat['lname'] . ', ' . $pat['fname']) . ' (' . text($pat['pubpid']) . ')'; } ?>
        <?php if ($encounter) { ?> — <?php echo xlt('Encounter'); ?> <?php echo text((string)$encounter); ?><?php } ?>
    </p>
    <?php if ($message) { ?><div class="alert alert-success"><?php echo text($message); ?></div><?php } ?>
    <?php if ($error) { ?><div class="alert alert-danger"><?php echo text($error); ?></div><?php } ?>

    <?php if (!empty($existing)) { ?>
        <h5><?php echo xlt('Prescribed this visit'); ?></h5>
        <table class="table table-sm table-bordered w-auto mb-4">
            <thead class="thead-light"><tr>
                <th><?php echo xlt('Medication'); ?></th><th><?php echo xlt('Dose'); ?></th>
                <th><?php echo xlt('Freq'); ?></th><th class="text-end"><?php echo xlt('Qty'); ?></th>
                <th><?php echo xlt('Unit'); ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($existing as $e) { ?>
                <tr>
                    <td><?php echo text($e['drug']); ?></td>
                    <td><?php echo text($e['dosage']); ?></td>
                    <td><?php echo text($e['freq']); ?></td>
                    <td class="text-end"><?php echo text($e['quantity']); ?></td>
                    <td><?php echo text($e['unit']); ?></td>
                    <td>
                        <?php if ($e['at_pharmacy']) { ?>
                            <span class="badge bg-info"><?php echo xlt('At pharmacy'); ?></span>
                        <?php } else { ?>
                            <form method="post" action="<?php echo attr($self . $qs); ?>" class="d-inline"
                                  onsubmit="return confirm('<?php echo xla('Remove this medicine?'); ?>');">
                                <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="rx_id" value="<?php echo attr((string)$e['id']); ?>">
                                <button class="btn btn-sm btn-outline-danger"><?php echo xlt('Remove'); ?></button>
                            </form>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } ?>

    <datalist id="druglist">
        <?php foreach ($catalogue as $d) { ?>
            <option value="<?php echo attr($d['name']); ?>"></option>
        <?php } ?>
    </datalist>

    <form method="post" action="<?php echo attr($self . $qs); ?>" id="rxform">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="pid" value="<?php echo attr((string)$pid); ?>">
        <input type="hidden" name="encounter" value="<?php echo attr((string)$encounter); ?>">
        <table class="table table-sm table-bordered" id="rxgrid">
            <thead class="thead-dark"><tr>
                <th><?php echo xlt('Drug'); ?></th>
                <th><?php echo xlt('Dose'); ?></th>
                <th><?php echo xlt('Unit'); ?></th>
                <th><?php echo xlt('Frequency'); ?></th>
                <th><?php echo xlt('Days'); ?></th>
                <th><?php echo xlt('Quantity'); ?></th>
                <th></th>
            </tr></thead>
            <tbody id="rxbody"></tbody>
        </table>
        <button type="button" class="btn btn-success btn-sm" id="rxadd">+ <?php echo xlt('Add drug'); ?></button>
        <button type="submit" class="btn btn-primary"><?php echo xlt('Save prescriptions'); ?></button>
    </form>

    <template id="rxrow">
        <tr>
            <td>
                <input type="text" class="form-control form-control-sm rx-drug" list="druglist"
                       autocomplete="off" placeholder="<?php echo xla('Type drug name'); ?>" data-role="drug">
                <input type="hidden" name="drug_id[]" data-role="drugid" value="0">
            </td>
            <td><input type="number" step="0.5" min="0" class="form-control form-control-sm" name="dose[]" data-role="dose"></td>
            <td>
                <select class="form-control form-control-sm" name="unit[]" data-role="unit">
                    <?php foreach ($units as $oid => $title) {
                        if ($oid === '0' || $title === '') { continue; } ?>
                        <option value="<?php echo attr($oid); ?>"><?php echo text($title); ?></option>
                    <?php } ?>
                </select>
            </td>
            <td>
                <select class="form-control form-control-sm" name="freq[]" data-role="freq">
                    <?php foreach (OpdPrescribeService::FREQUENCIES as $code => $f) { ?>
                        <option value="<?php echo attr($code); ?>"
                            data-perday="<?php echo attr($f['per_day'] === null ? '' : (string)$f['per_day']); ?>">
                            <?php echo text($f['label']); ?>
                        </option>
                    <?php } ?>
                </select>
            </td>
            <td><input type="number" step="1" min="0" class="form-control form-control-sm" name="days[]" data-role="days"></td>
            <td><input type="number" step="1" min="0" class="form-control form-control-sm rx-qty" name="qty[]" data-role="qty"></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger" data-role="del">&times;</button></td>
        </tr>
    </template>

    <script>
        const OPD_DRUGS = <?php echo json_encode($drugMap, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const body = document.getElementById('rxbody');
        const tpl = document.getElementById('rxrow');

        function cell(tr, role) { return tr.querySelector('[data-role="' + role + '"]'); }

        function resolveDrug(tr) {
            const name = cell(tr, 'drug').value;
            const hit = OPD_DRUGS[name];
            cell(tr, 'drugid').value = hit ? hit.id : 0;
            if (hit && hit.form && hit.form !== '0') {
                const u = cell(tr, 'unit');
                if (u.querySelector('option[value="' + hit.form + '"]')) { u.value = hit.form; }
            }
        }

        function calcQty(tr) {
            const freqOpt = cell(tr, 'freq').selectedOptions[0];
            const perday = freqOpt ? freqOpt.dataset.perday : '';
            const dose = parseFloat(cell(tr, 'dose').value);
            const days = parseInt(cell(tr, 'days').value, 10);
            if (perday !== '' && !isNaN(dose) && !isNaN(days) && days > 0) {
                cell(tr, 'qty').value = Math.ceil(dose * parseFloat(perday) * days);
            }
        }

        function addRow() {
            body.appendChild(tpl.content.cloneNode(true));
        }

        body.addEventListener('input', function (e) {
            const tr = e.target.closest('tr');
            const role = e.target.dataset.role;
            if (role === 'drug') { resolveDrug(tr); }
            if (['dose', 'days'].includes(role)) { calcQty(tr); }
        });
        body.addEventListener('change', function (e) {
            if (e.target.dataset.role === 'freq') { calcQty(e.target.closest('tr')); }
        });
        body.addEventListener('click', function (e) {
            if (e.target.dataset.role === 'del') {
                e.target.closest('tr').remove();
                if (!body.children.length) { addRow(); }
            }
        });
        document.getElementById('rxadd').addEventListener('click', addRow);

        addRow(); // start with one empty row
    </script>
</body>
</html>
