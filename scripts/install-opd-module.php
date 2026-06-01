<?php
/**
 * Register + install + enable the oe-module-opd custom module.
 *
 * Reproduces what Admin → Modules → Manage Modules does for a custom (type=0)
 * module, so the build is reproducible after a DB reset (matches the project's
 * PHP-seed pattern, e.g. seed-pharmacy-acl.php). Idempotent.
 *
 * Run inside the openemr container:
 *   docker compose -f docker/development-easy/docker-compose.yml exec -T openemr \
 *     php /var/www/localhost/htdocs/openemr/scripts/install-opd-module.php
 */

$ignoreAuth = true;
$_GET['site'] = 'default';
$_SESSION['site_id'] = 'default';
$_SERVER['HTTP_HOST'] = 'localhost';
require_once(__DIR__ . '/../interface/globals.php');

$directory = 'oe-module-opd';
$modPath = $GLOBALS['fileroot'] . '/interface/modules/custom_modules/' . $directory;

// ── 1. Register the modules row (if absent) ────────────────────────────────
$existing = sqlQuery("SELECT mod_id, mod_active FROM modules WHERE mod_directory = ?", [$directory]);
if (empty($existing['mod_id'])) {
    $lines = @file($modPath . '/info.txt');
    $name = !empty($lines) ? trim($lines[0]) : $directory;
    $uiname = ucwords(strtolower($directory));
    $relLink = strtolower('custom_modules/' . $directory);

    sqlStatement(
        "INSERT INTO modules
           SET mod_name = ?, mod_active = 1, mod_ui_name = ?, mod_relative_link = ?,
               mod_directory = ?, type = 0, sql_run = 1, date = NOW()",
        [$name, $uiname, $relLink, $directory]
    );
    // Re-query: sqlGetLastInsertId() is unreliable for this table (uses the
    // shared `sequences` mechanism), so read the real auto-increment id back.
    $modId = (int) (sqlQuery("SELECT mod_id FROM modules WHERE mod_directory = ?", [$directory])['mod_id'] ?? 0);
    echo "registered module: mod_id=$modId\n";

    // ACL section row, mirroring InstModuleTable::register()
    $hasSection = sqlQuery("SELECT section_id FROM module_acl_sections WHERE module_id = ?", [$modId]);
    if (empty($hasSection['section_id'])) {
        sqlStatement(
            "INSERT INTO module_acl_sections (section_id, section_name, parent_section, section_identifier, module_id)
             VALUES (?,?,0,?,?)",
            [$modId, $name, strtolower($directory), $modId]
        );
    }
} else {
    $modId = (int) $existing['mod_id'];
    sqlStatement("UPDATE modules SET mod_active = 1, sql_run = 1 WHERE mod_id = ?", [$modId]);
    echo "module already registered: mod_id=$modId (ensured active)\n";
}

// ── 2. Run install.sql (strip OpenEMR #IfNotTable directives; CREATE ... IF NOT EXISTS is idempotent) ──
$sqlFile = $modPath . '/sql/install.sql';
$raw = file_get_contents($sqlFile);
// Drop directive lines and comments
$clean = preg_replace('/^\s*#.*$/m', '', $raw);
$clean = preg_replace('/^\s*--.*$/m', '', $clean);
foreach (array_filter(array_map('trim', explode(';', $clean))) as $stmt) {
    sqlStatement($stmt);
}
echo "ran install.sql\n";

// ── 3. Verify ──────────────────────────────────────────────────────────────
$row = sqlQuery("SELECT mod_id, mod_name, mod_directory, mod_active, type, sql_run FROM modules WHERE mod_directory = ?", [$directory]);
echo "modules row: " . json_encode($row) . "\n";
$t1 = sqlQuery("SHOW TABLES LIKE 'opd_clinic_config'");
$t2 = sqlQuery("SHOW TABLES LIKE 'opd_provider_clinic'");
echo "tables: opd_clinic_config=" . (!empty($t1) ? 'yes' : 'NO') . ", opd_provider_clinic=" . (!empty($t2) ? 'yes' : 'NO') . "\n";
