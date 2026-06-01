<?php

/**
 * OPD Workflow — Reception: start visit + bill registration/consultation.
 *
 * Reception registers the patient via the native New Patient form (unchanged),
 * then uses this screen to: find the patient, pick the clinic, and start the
 * visit. Starting the visit creates today's appointment in the clinic, ensures
 * the encounter exists (carrying the clinic), puts the patient on the board at
 * RG, and bills Registration (first visit) + the clinic consultation. The
 * patient reaches the nurse only after the cashier marks payment (status PD).
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

// Reception = front office: needs appointment + demographics access.
if (!AclMain::aclCheckCore('patients', 'appt') && !AclMain::aclCheckCore('patients', 'demo')) {
    echo xlt('Access denied');
    exit;
}

$clinicService = new OpdClinicService();
$clinics = $clinicService->getClinics();

$message = '';
$error = '';
$result = null;

// ── Handle "start visit" POST ──────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    $pid = (int)($_POST['pid'] ?? 0);
    $clinicCatid = (int)($_POST['pc_catid'] ?? 0);

    try {
        if ($pid <= 0 || $clinicCatid <= 0) {
            throw new \RuntimeException(xl('Select a patient and a clinic.'));
        }
        // Reception allocates to a CLINIC only; the triage nurse assigns the doctor.
        $svc = new OpdVisitService();
        $result = $svc->startVisit($pid, $clinicCatid, null);
        if (!empty($result['already_active'])) {
            $message = '';
        } else {
            $message = xl('Visit started') . ' — ' . xl('Encounter') . ' #' . (int)$result['encounter'];
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Patient context ────────────────────────────────────────────────────────
$selectedPid = (int)($_GET['pid'] ?? ($_POST['pid'] ?? 0));
$patient = null;
$patientBalance = 0.0;
if ($selectedPid > 0) {
    $patient = sqlQuery(
        "SELECT pid, fname, lname, DOB, sex, pubpid FROM patient_data WHERE pid = ?",
        [$selectedPid]
    );
    if ($patient) {
        $patientBalance = (new OpdGatingService())->getPatientBalance($selectedPid);
        $activeVisits = (new OpdVisitService())->getActiveVisits($selectedPid);
    }
}
$activeVisits = $activeVisits ?? [];

// ── Patient search ─────────────────────────────────────────────────────────
$searchTerm = trim($_GET['search'] ?? '');
$matches = [];
if ($searchTerm !== '') {
    $like = '%' . $searchTerm . '%';
    $res = sqlStatement(
        "SELECT pid, fname, lname, DOB, pubpid FROM patient_data
         WHERE pubpid LIKE ? OR lname LIKE ? OR fname LIKE ? OR CONCAT(fname,' ',lname) LIKE ?
         ORDER BY lname, fname LIMIT 25",
        [$like, $like, $like, $like]
    );
    while ($row = sqlFetchArray($res)) {
        $matches[] = $row;
    }
}

$csrf = CsrfUtils::collectCsrfToken();
$self = $_SERVER['PHP_SELF'];
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('OPD Reception — Start Visit'); ?></title>
    <?php Header::setupHeader(['common']); ?>
</head>
<body class="body_top container mt-3">
    <h2><?php echo xlt('Reception — Start OPD Visit'); ?></h2>

    <?php if (!empty($_GET['is_new']) && $patient && !$result) { ?>
        <div class="alert alert-info"><?php echo xlt('Patient registered. Allocate them to a clinic below to start the OPD visit (Registration + consultation will be billed).'); ?></div>
    <?php } ?>

    <?php if ($message) { ?>
        <div class="alert alert-success"><?php echo text($message); ?></div>
    <?php } ?>
    <?php if ($error) { ?>
        <div class="alert alert-danger"><?php echo text($error); ?></div>
    <?php } ?>

    <?php if ($result && !empty($result['already_active'])) { ?>
        <div class="card mb-3 border-warning"><div class="card-body">
            <h5><?php echo xlt('Patient already has an active visit'); ?></h5>
            <p>
                <strong><?php echo text(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? '')); ?></strong>
                <?php echo xlt('already has an active visit in'); ?> <strong><?php echo text($result['clinic']); ?></strong>
                — <?php echo text($result['status_label']); ?>,
                <?php echo xlt('since'); ?> <?php echo text($result['started']); ?>
                (<?php echo xlt('Encounter'); ?> #<?php echo text($result['encounter']); ?>).
            </p>
            <p class="text-muted"><?php echo xlt('No new registration or charge is needed — direct the patient to the relevant station (cashier / nurse / doctor).'); ?></p>
            <a class="btn btn-outline-primary btn-sm" href="<?php echo attr($self); ?>"><?php echo xlt('Start another visit'); ?></a>
        </div></div>
    <?php } elseif ($result) { ?>
        <div class="card mb-3"><div class="card-body">
            <h5><?php echo xlt('Visit summary'); ?></h5>
            <p>
                <?php echo xlt('Patient'); ?>: <strong><?php echo text(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? '')); ?></strong>
                &nbsp;|&nbsp; <?php echo xlt('Clinic'); ?>: <strong><?php echo text($result['clinic']); ?></strong>
                &nbsp;|&nbsp; <?php echo xlt('Encounter'); ?>: <strong>#<?php echo text($result['encounter']); ?></strong>
                <?php if ($result['reused']) { ?><span class="badge bg-info"><?php echo xlt('existing visit reused'); ?></span><?php } ?>
            </p>
            <table class="table table-sm w-auto">
                <thead><tr><th><?php echo xlt('Charge'); ?></th><th><?php echo xlt('Code'); ?></th><th class="text-end"><?php echo xlt('Fee (TSh)'); ?></th><th><?php echo xlt('Status'); ?></th></tr></thead>
                <tbody>
                <?php $total = 0; foreach ($result['charges'] as $c) { $total += (float)$c['fee']; ?>
                    <tr>
                        <td><?php echo text($c['text']); ?></td>
                        <td><?php echo text($c['code']); ?></td>
                        <td class="text-end"><?php echo text(number_format((float)$c['fee'], 0)); ?></td>
                        <td><?php echo $c['added'] ? '<span class="badge bg-success">' . xlt('billed') . '</span>' : '<span class="badge bg-secondary">' . xlt('already billed') . '</span>'; ?></td>
                    </tr>
                <?php } ?>
                </tbody>
                <tfoot><tr><th colspan="2"><?php echo xlt('Total this visit'); ?></th><th class="text-end"><?php echo text(number_format($total, 0)); ?></th><th></th></tr></tfoot>
            </table>
            <p class="text-muted"><?php echo xlt('Send the patient to the Cashier. The nurse will see the patient only after payment is recorded.'); ?></p>
            <a class="btn btn-outline-primary btn-sm" href="<?php echo attr($self); ?>"><?php echo xlt('Start another visit'); ?></a>
        </div></div>
    <?php } ?>

    <?php if (!$result) { ?>
    <div class="card mb-3"><div class="card-body">
        <h5><?php echo xlt('1. Find patient'); ?></h5>
        <form method="get" action="<?php echo attr($self); ?>" class="form-inline">
            <input type="text" class="form-control mr-2" name="search" value="<?php echo attr($searchTerm); ?>"
                   placeholder="<?php echo attr(xl('Name or Registration No.')); ?>" autofocus>
            <button class="btn btn-primary" type="submit"><?php echo xlt('Search'); ?></button>
            <span class="ml-3 text-muted"><?php echo xlt('New patient? Register first via the New Patient form, then search here.'); ?></span>
        </form>

        <?php if ($searchTerm !== '') { ?>
            <table class="table table-sm table-hover mt-3 w-auto">
                <thead><tr><th><?php echo xlt('Name'); ?></th><th><?php echo xlt('Reg No.'); ?></th><th><?php echo xlt('DOB'); ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($matches as $m) { ?>
                    <tr>
                        <td><?php echo text($m['lname'] . ', ' . $m['fname']); ?></td>
                        <td><?php echo text($m['pubpid']); ?></td>
                        <td><?php echo text($m['DOB']); ?></td>
                        <td><a class="btn btn-sm btn-success" href="<?php echo attr($self . '?pid=' . (int)$m['pid']); ?>"><?php echo xlt('Select'); ?></a></td>
                    </tr>
                <?php } ?>
                <?php if (empty($matches)) { ?>
                    <tr><td colspan="4"><?php echo xlt('No patients found.'); ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    </div></div>

    <?php if ($patient) { ?>
        <div class="card"><div class="card-body">
            <h5><?php echo xlt('2. Start visit for'); ?>
                <strong><?php echo text($patient['fname'] . ' ' . $patient['lname']); ?></strong>
                <small class="text-muted">(<?php echo xlt('Reg No.'); ?> <?php echo text($patient['pubpid']); ?>)</small>
            </h5>
            <?php if ($patientBalance > OpdGatingService::PAID_EPSILON) { ?>
                <div class="alert alert-warning py-2">
                    <?php echo xlt('Outstanding balance'); ?>:
                    <strong><?php echo text('TSh ' . number_format($patientBalance, 0)); ?></strong>
                    — <?php echo xlt('please direct the patient to the cashier.'); ?>
                </div>
            <?php } else { ?>
                <div class="text-success small mb-2"><?php echo xlt('No outstanding balance.'); ?></div>
            <?php } ?>
            <?php if (!empty($activeVisits)) { ?>
                <div class="alert alert-warning py-2">
                    <strong><?php echo xlt('Active visit(s):'); ?></strong>
                    <?php foreach ($activeVisits as $av) { ?>
                        <div><?php echo text($av['clinic'] . ' — ' . $av['status_label'] . ' (' . xl('since') . ' ' . $av['started'] . ')'); ?></div>
                    <?php } ?>
                    <small class="text-muted"><?php echo xlt('Choosing a clinic where a visit is already active will not start a new one.'); ?></small>
                </div>
            <?php } ?>
            <form method="post" action="<?php echo attr($self); ?>">
                <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                <input type="hidden" name="pid" value="<?php echo attr($patient['pid']); ?>">
                <div class="form-group">
                    <label><?php echo xlt('Clinic'); ?></label>
                    <select name="pc_catid" class="form-control w-auto" required>
                        <option value=""><?php echo xlt('-- Select clinic --'); ?></option>
                        <?php foreach ($clinics as $c) { ?>
                            <option value="<?php echo attr($c['pc_catid']); ?>">
                                <?php echo text($c['pc_catname'] . '  (' . $c['consultation_code'] . ' — ' . number_format((float)$c['consultation_fee'], 0) . ' TSh)'); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <p class="text-muted small mb-2"><?php echo xlt('The triage nurse will assign the doctor.'); ?></p>
                <button type="submit" class="btn btn-primary"><?php echo xlt('Allocate to clinic & bill'); ?></button>
                <a class="btn btn-outline-secondary" href="<?php echo attr($self); ?>"><?php echo xlt('Cancel'); ?></a>
            </form>
        </div></div>
    <?php } ?>
    <?php } ?>
</body>
</html>
