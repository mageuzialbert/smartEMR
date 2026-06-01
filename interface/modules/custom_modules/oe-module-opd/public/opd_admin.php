<?php

/**
 * OPD Workflow — admin landing / config overview.
 *
 * Read-only overview of clinics (categories + fee codes) and provider→clinic
 * grants. Confirms the module autoloads and its tables exist.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

require_once(__DIR__ . "/../../../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;
use OpenEMR\Modules\OpdWorkflow\Service\OpdClinicService;

if (!AclMain::aclCheckCore('admin', 'super')) {
    echo xlt('Access denied');
    exit;
}

$svc = new OpdClinicService();
$clinics = $svc->getClinics();

$grants = [];
$res = sqlStatement(
    "SELECT pc.provider_id, u.fname, u.lname, cat.pc_catname
     FROM opd_provider_clinic pc
     JOIN users u ON u.id = pc.provider_id
     JOIN openemr_postcalendar_categories cat ON cat.pc_catid = pc.pc_catid
     ORDER BY u.lname, cat.pc_seq"
);
while ($row = sqlFetchArray($res)) {
    $grants[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('OPD Workflow'); ?></title>
    <?php Header::setupHeader(['common']); ?>
</head>
<body class="body_top container mt-3">
    <h2><?php echo xlt('OPD Workflow — Configuration'); ?></h2>

    <h4 class="mt-4"><?php echo xlt('Clinics'); ?></h4>
    <table class="table table-sm table-bordered w-auto">
        <thead><tr>
            <th><?php echo xlt('Clinic'); ?></th>
            <th><?php echo xlt('Category id'); ?></th>
            <th><?php echo xlt('Consultation code'); ?></th>
            <th><?php echo xlt('Fee'); ?></th>
            <th><?php echo xlt('Registration code'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($clinics as $c) { ?>
            <tr>
                <td><?php echo text($c['pc_catname']); ?></td>
                <td><?php echo text($c['pc_catid']); ?></td>
                <td><?php echo text($c['consultation_code']); ?></td>
                <td><?php echo text($c['consultation_fee'] ?? ''); ?></td>
                <td><?php echo text($c['requires_registration'] ? $c['registration_code'] : '—'); ?></td>
            </tr>
        <?php } ?>
        <?php if (empty($clinics)) { ?>
            <tr><td colspan="5"><?php echo xlt('No clinics configured yet (run scripts/seed-opd-config.sql).'); ?></td></tr>
        <?php } ?>
        </tbody>
    </table>

    <h4 class="mt-4"><?php echo xlt('Doctor → Clinic grants'); ?></h4>
    <table class="table table-sm table-bordered w-auto">
        <thead><tr>
            <th><?php echo xlt('Doctor'); ?></th>
            <th><?php echo xlt('Clinic'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($grants as $g) { ?>
            <tr>
                <td><?php echo text($g['fname'] . ' ' . $g['lname']); ?></td>
                <td><?php echo text($g['pc_catname']); ?></td>
            </tr>
        <?php } ?>
        <?php if (empty($grants)) { ?>
            <tr><td colspan="2"><?php echo xlt('No provider grants yet (run scripts/seed-opd-config.sql).'); ?></td></tr>
        <?php } ?>
        </tbody>
    </table>
</body>
</html>
