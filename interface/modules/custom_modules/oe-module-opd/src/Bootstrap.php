<?php

/**
 * OPD Workflow Module — Bootstrap class.
 *
 * Wires the module's event subscribers. For role exclusivity the per-role queue
 * links live in the role menu JSONs (sites/.../custom_menus/); here we only inject
 * an admin-only entry to reach the OPD config/landing page.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

namespace OpenEMR\Modules\OpdWorkflow;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Encounter\EncounterMenuEvent;
use OpenEMR\Menu\MenuEvent;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/oe-module-opd";
    const MODULE_NAME = "oe-module-opd";

    private SystemLogger $logger;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
        $this->logger = new SystemLogger();
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addAdminMenuItem(...));
        // (Dispensed-medications block removed — the native newpatient report already shows it, and
        //  with the OPD summary reorder that block now sits last. Avoids showing meds twice.)
        // Negative priority so this runs AFTER forms.php populates the menu.
        $this->eventDispatcher->addListener(
            EncounterMenuEvent::MENU_RENDER,
            $this->filterEncounterMenu(...),
            -100
        );
    }

    /**
     * Trim the encounter "Add New Form" menu to the lean OPD set: Clinical keeps
     * only Vitals, OPD Visit Note, and Clinical Instructions. Administrative keeps
     * only the Fee Sheet for billers (cashier); for non-billers (the doctor) the
     * editable Fee Sheet is replaced by a read-only "Patient Bill" view so they can
     * see — but not edit — the bill. Other categories (Orders, Questionnaires,
     * module forms) are left untouched. Admin/super keep the full menu.
     */
    public function filterEncounterMenu(EncounterMenuEvent $event): EncounterMenuEvent
    {
        if (AclMain::aclCheckCore('admin', 'super')) {
            return $event;
        }

        $menu = $event->getMenuData();

        // Clinical: keep only the OPD clinical forms, then add Prescriptions for prescribers.
        $clinKey = xl('Clinical');
        if (!empty($menu[$clinKey]['children'])) {
            $keep = ['vitals', 'LBFopd_visit', 'clinical_instructions'];
            $menu[$clinKey]['children'] = array_values(array_filter(
                $menu[$clinKey]['children'],
                fn($item) => in_array($item['directory'] ?? '', $keep, true)
            ));
            // OPD Visit Note + Vitals: route through their openers so clicking them REOPENS the
            // encounter's existing instance (one note / one vitals per visit) instead of creating a
            // new blank one each time.
            $clinEnc = (int)($_SESSION['encounter'] ?? 0);
            $clinPid = (int)($_SESSION['pid'] ?? 0);
            foreach ($menu[$clinKey]['children'] as &$clinItem) {
                $dir = $clinItem['directory'] ?? '';
                if ($dir === 'LBFopd_visit') {
                    $clinItem = [
                        'displayText' => xl('OPD Visit Note'),
                        'href' => $GLOBALS['webroot'] . self::MODULE_INSTALLATION_PATH
                            . '/public/opd_visit_note.php?encounter=' . $clinEnc,
                    ];
                } elseif ($dir === 'vitals') {
                    $clinItem = [
                        'displayText' => xl('Vitals'),
                        'href' => $GLOBALS['webroot'] . self::MODULE_INSTALLATION_PATH
                            . '/public/opd_vitals.php?pid=' . $clinPid . '&encounter=' . $clinEnc,
                    ];
                }
            }
            unset($clinItem);
            // Prescriptions: the fast multi-row OPD grid (oe-module-opd/public/prescribe.php), opened
            // in an encounter sub-tab for a prescriber (patients/rx — the doctor). Writes standard
            // prescriptions rows that feed the pharmacy worklist.
            if (AclMain::aclCheckCore('patients', 'rx')) {
                $rxPid = (int)($_SESSION['pid'] ?? 0);
                $rxEnc = (int)($_SESSION['encounter'] ?? 0);
                $menu[$clinKey]['children'][] = [
                    'displayText' => xl('Prescriptions'),
                    'href' => $GLOBALS['webroot'] . self::MODULE_INSTALLATION_PATH
                        . '/public/prescribe.php?pid=' . $rxPid . '&encounter=' . $rxEnc,
                ];
            }
            if (empty($menu[$clinKey]['children'])) {
                unset($menu[$clinKey]);
            }
        }

        // Orders: replace the single "Procedure Order" item with one entry per department
        // (procedure_providers). Each opens the order form already scoped to that department
        // via ?lab_id=N, so the doctor never touches the in-form Department selector. href
        // children open in an encounter sub-tab (load_form.php pipeline) like "Patient Bill".
        $ordKey = xl('Orders');
        if (!empty($menu[$ordKey]['children'])) {
            $ordEnc = (int)($_SESSION['encounter'] ?? 0);
            $deptChildren = [];
            $pp = sqlStatement("SELECT ppid, name FROM procedure_providers WHERE active = 1 ORDER BY name, ppid");
            while ($d = sqlFetchArray($pp)) {
                // Open via the opener so the department's EXISTING order reopens (add more procedures)
                // instead of creating a new order each time — one order per department per encounter.
                $deptChildren[] = [
                    'displayText' => $d['name'],
                    'href' => $GLOBALS['webroot'] . self::MODULE_INSTALLATION_PATH
                        . '/public/opd_order.php?lab_id=' . (int)$d['ppid'] . '&encounter=' . $ordEnc,
                ];
            }
            if (!empty($deptChildren)) {
                $menu[$ordKey]['children'] = $deptChildren;
            }
        }

        // Administrative: billers keep the Fee Sheet; non-billers get a read-only bill.
        $admKey = xl('Administrative');
        if (isset($menu[$admKey])) {
            if (AclMain::aclCheckCore('acct', 'bill')) {
                $menu[$admKey]['children'] = array_values(array_filter(
                    $menu[$admKey]['children'] ?? [],
                    fn($item) => ($item['directory'] ?? '') === 'fee_sheet'
                ));
                if (empty($menu[$admKey]['children'])) {
                    unset($menu[$admKey]);
                }
            } else {
                // Modify in place to preserve the category's position in the menu.
                $menu[$admKey]['children'] = [[
                    'displayText' => xl('Patient Bill'),
                    'href' => $GLOBALS['webroot'] . self::MODULE_INSTALLATION_PATH
                        . '/public/encounter_bill_view.php',
                ]];
            }
        }

        $event->setMenuData($menu);
        return $event;
    }


    /**
     * Add an admin-only "OPD Workflow" entry under the Admin menu.
     */
    public function addAdminMenuItem(MenuEvent $event): MenuEvent
    {
        $menu = $event->getMenu();

        foreach ($menu as $menuItem) {
            if (($menuItem->menu_id ?? '') === 'admimg' || ($menuItem->label ?? '') === 'Admin') {
                $item = new stdClass();
                $item->requirement = 0;
                $item->target = 'adm0';
                $item->menu_id = 'opdadm0';
                $item->label = xlt("OPD Workflow");
                $item->url = self::MODULE_INSTALLATION_PATH . "/public/opd_admin.php";
                $item->children = [];
                $item->acl_req = ["admin", "super"];
                $item->global_req = [];
                $menuItem->children[] = $item;
                break;
            }
        }

        $event->setMenu($menu);
        return $event;
    }
}
