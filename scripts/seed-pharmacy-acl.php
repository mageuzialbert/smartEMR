<?php
/**
 * OPD workflow v2 — pharmacist-only dispensing.
 *
 * The admin/drugs permission (inventory + dispense screens) is granted out of the box by
 * BOTH the Clinicians (acl 18) and Physicians (acl 14) groups, so nurses, lab and doctors
 * can all dispense. This restricts dispensing to pharm1: a dedicated "Pharmacy" ARO group
 * gets admin/drugs, and the ACO is removed from the Clinicians and Physicians ACLs.
 * Administrators (acl 10) and Emergency Login (acl 27) keep it.
 *
 * Idempotent. Run inside the openemr container:
 *   docker compose -f docker/development-easy/docker-compose.yml exec -T openemr \
 *     php /var/www/localhost/htdocs/openemr/scripts/seed-pharmacy-acl.php
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

// 1. ARO group "Pharmacy" (value 'pharmacy') under root group 'users' (id 10)
$existing = sqlQuery("SELECT id FROM gacl_aro_groups WHERE value = 'pharmacy'");
if (!empty($existing['id'])) {
    $gid = (int) $existing['id'];
    echo "group exists: id=$gid\n";
} else {
    $gid = $g->add_group('pharmacy', 'Pharmacy', 10, 'ARO');
    echo "created group: id=$gid\n";
}

// 2. ACL granting admin/drugs to that group (skip if already present)
$existAcl = sqlQuery(
    "SELECT a.id FROM gacl_acl a
     JOIN gacl_aro_groups_map m ON m.acl_id = a.id
     JOIN gacl_aco_map am ON am.acl_id = a.id
     WHERE m.group_id = ? AND am.section_value = 'admin' AND am.value = 'drugs' LIMIT 1",
    [$gid]
);
if (!empty($existAcl['id'])) {
    echo "acl already grants admin/drugs to group\n";
} else {
    $aclid = $g->add_acl(['admin' => ['drugs']], null, [$gid], null, null, 1, 1, 'write', 'Pharmacy role: inventory + dispense', null);
    echo "created acl: " . var_export($aclid, true) . "\n";
}

// 3. Add pharm1 to the group (keeps existing Clinicians membership for clinical read access)
AclExtended::addUserAros('pharm1', 'Pharmacy');
echo "pharm1 groups: " . implode(', ', AclExtended::aclGetGroupTitles('pharm1')) . "\n";

// 4. Remove admin/drugs (ACO) from the Clinicians (acl 18) and Physicians (acl 14) write ACLs.
foreach ([18 => 'Clinicians', 14 => 'Physicians'] as $acl_id => $label) {
    $g->shift_acl($acl_id, null, null, null, null, ['admin' => ['drugs']]);
    echo "removed admin/drugs from acl $acl_id ($label)\n";
}

// 5. Verify: only pharmacist (and admin/breakglass) can dispense now.
foreach (['pharm1', 'doctor1', 'nurse1', 'lab1', 'admin'] as $u) {
    echo "$u admin/drugs = " . (AclMain::aclCheckCore('admin', 'drugs', $u) ? 'ALLOW' : 'deny') . "\n";
}
