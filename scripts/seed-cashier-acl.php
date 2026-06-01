<?php
/**
 * OPD re-architecture — restrict the Cashier (Accounting group) from clinical encounter access.
 *
 * The Accounting group's "write" ACL grants encounters/auth_a, encounters/coding_a and
 * encounters/date_a — i.e. write access to ANY encounter (authorize / code / re-date). A cashier
 * must never touch encounter content. This removes only those three ACOs from the Accounting
 * ACL(s), keeping all billing access (acct/*, admin/practice, admin/superbill, patients/demo|appt).
 *
 * Uses the supported GaclApi::shift_acl() (removes ACOs from an ACL; leaves the ACL otherwise
 * intact). Idempotent — re-running finds nothing to remove. Mirrors scripts/seed-pharmacy-acl.php.
 *
 * Run inside the openemr container:
 *   docker compose -f docker/development-easy/docker-compose.yml exec -T openemr \
 *     php /var/www/localhost/htdocs/openemr/scripts/seed-cashier-acl.php
 */

$ignoreAuth = true;
$_GET['site'] = 'default';
$_SESSION['site_id'] = 'default';
$_SERVER['HTTP_HOST'] = 'localhost';
require_once(__DIR__ . '/../interface/globals.php');

use OpenEMR\Gacl\GaclApi;

$g = new GaclApi();

$grp = sqlQuery("SELECT id FROM gacl_aro_groups WHERE name = 'Accounting' LIMIT 1");
$gid = (int)($grp['id'] ?? 0);
if (!$gid) {
    echo "Accounting group not found — nothing to do.\n";
    return;
}

$remove = ['encounters' => ['auth_a', 'coding_a', 'date_a']];

$acls = sqlStatement(
    "SELECT DISTINCT m.acl_id
     FROM gacl_aro_groups_map m
     JOIN gacl_aco_map am ON am.acl_id = m.acl_id
     WHERE m.group_id = ? AND am.section_value = 'encounters' AND am.value IN ('auth_a','coding_a','date_a')",
    [$gid]
);

$count = 0;
while ($row = sqlFetchArray($acls)) {
    $g->shift_acl((int)$row['acl_id'], null, null, null, null, $remove);
    echo "removed encounters/auth_a|coding_a|date_a from ACL " . (int)$row['acl_id'] . "\n";
    $count++;
}

echo "processed $count ACL(s). (Verify with aclCheckCore in a fresh process — the ACL cache is per-request.)\n";
