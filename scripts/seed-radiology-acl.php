<?php
/**
 * OPD Phase 9 — dedicated "Radiologists" ACL role.
 *
 * Radiology result entry (interface/orders/orders_results.php) is gated on patients/sign,
 * which the Clinicians base group lacks. Mirroring scripts/seed-lab-acl.php, we create a
 * dedicated ARO group that grants ONLY patients/sign and add radiology1 to it. radiology1
 * keeps its Clinicians membership (base clinical access incl. patients/lab); nurse1/pharm1
 * are unaffected. The department guard (OpdOrderBillingService) restricts radiology1 to
 * Clinic Radiology (ppid 2) orders only.
 *
 * Idempotent. Run inside the openemr container:
 *   docker compose -f docker/development-easy/docker-compose.yml exec -T openemr \
 *     php /var/www/localhost/htdocs/openemr/scripts/seed-radiology-acl.php
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

// 1. ARO group "Radiologists" (value 'radiology') under root group 'users' (id 10).
$existing = sqlQuery("SELECT id FROM gacl_aro_groups WHERE value = 'radiology'");
if (!empty($existing['id'])) {
    $gid = (int) $existing['id'];
    echo "group exists: id=$gid\n";
} else {
    $gid = $g->add_group('radiology', 'Radiologists', 10, 'ARO');
    echo "created group: id=$gid\n";
}

// 2. ACL granting patients/sign to that group (skip if already present).
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
        'Radiology role: enter/sign radiology results',
        null
    );
    echo "created acl: " . var_export($aclid, true) . "\n";
}

// 3. Add radiology1 to the group (preserves existing memberships incl. Clinicians).
AclExtended::addUserAros('radiology1', 'Radiologists');
echo "radiology1 groups: " . implode(', ', AclExtended::aclGetGroupTitles('radiology1')) . "\n";

// 4. Verify isolation: radiology1 gains sign; nurse1/pharm1 do not.
foreach (['radiology1', 'nurse1', 'pharm1'] as $u) {
    echo "$u patients/sign = " . (AclMain::aclCheckCore('patients', 'sign', $u) ? 'ALLOW' : 'deny') . "\n";
}
