<?php
/**
 * Phase 12 pre-flight — dedicated "Lab Technicians" ACL role.
 *
 * Lab result entry/review (interface/orders/orders_results.php) is gated on the
 * patients/sign permission, which the Clinicians group lacks. Rather than grant the
 * whole Clinicians group (which would also give nurse1/pharm1 signing), we create a
 * dedicated ARO group that grants ONLY patients/sign and add lab1 to it. lab1 keeps
 * its Clinicians membership (base clinical access); nurse1/pharm1 are unaffected.
 *
 * Idempotent. Run inside the openemr container:
 *   docker compose -f docker/development-easy/docker-compose.yml exec -T openemr \
 *     php /var/www/localhost/htdocs/openemr/scripts/seed-lab-acl.php
 */

$ignoreAuth = true;
$_GET['site'] = 'default';
$_SESSION['site_id'] = 'default';
$_SERVER['HTTP_HOST'] = 'localhost';
require_once(__DIR__ . '/../interface/globals.php');

use OpenEMR\Gacl\GaclApi;
use OpenEMR\Common\Acl\AclExtended;
use OpenEMR\Common\Acl\AclMain;

$g = new GaclApi();

// 1. ARO group "Lab Technicians" (value 'labtech') under root group 'users' (id 10)
$existing = sqlQuery("SELECT id FROM gacl_aro_groups WHERE value = 'labtech'");
if (!empty($existing['id'])) {
    $gid = (int) $existing['id'];
    echo "group exists: id=$gid\n";
} else {
    $gid = $g->add_group('labtech', 'Lab Technicians', 10, 'ARO');
    echo "created group: id=$gid\n";
}

// 2. ACL granting patients/sign to that group (skip if already present)
$existAcl = sqlQuery(
    "SELECT a.id FROM gacl_acl a
     JOIN gacl_aro_groups_map m ON m.acl_id = a.id
     JOIN gacl_aco_map am ON am.acl_id = a.id
     WHERE m.group_id = ? AND am.section_value = 'patients' AND am.value = 'sign' LIMIT 1",
    [$gid]
);
if (!empty($existAcl['id'])) {
    echo "acl already grants patients/sign to group\n";
} else {
    $aclid = $g->add_acl(
        ['patients' => ['sign']], // ACOs
        null,                      // individual AROs
        [$gid],                    // ARO group ids
        null,
        null,
        1,                         // allow
        1,                         // enabled
        'write',                   // return value
        'Lab role: enter/sign lab results',
        null
    );
    echo "created acl: " . var_export($aclid, true) . "\n";
}

// 3. Add lab1 to the group (addUserAros preserves existing memberships incl. Clinicians)
AclExtended::addUserAros('lab1', 'Lab Technicians');
echo "lab1 groups: " . implode(', ', AclExtended::aclGetGroupTitles('lab1')) . "\n";

// 4. Verify isolation: lab1 gains sign; nurse1/pharm1 do not.
foreach (['lab1', 'nurse1', 'pharm1'] as $u) {
    echo "$u patients/sign = " . (AclMain::aclCheckCore('patients', 'sign', $u) ? 'ALLOW' : 'deny') . "\n";
}
