<?php

/**
 * OPD Workflow — procedure-order billing + per-order payment gate.
 *
 * Two jobs, both built on native machinery (no bespoke money math):
 *
 *  1. syncOrderCharges() — when a doctor sends a Procedure Order, drop the matching
 *     service-code charge(s) onto the encounter Fee Sheet (BillingUtilities::addBilling),
 *     so the cashier has something to collect. Stock OpenEMR posts NO charge from a
 *     procedure order, which is why the lab/radiology/procedure legs had nothing to bill.
 *     procedure_order_code.procedure_code already IS the HCPCS service code (LAB-*, RAD-*,
 *     PROC-*), each priced in `prices` at pr_level='standard'. Idempotent per code.
 *
 *  2. isOrderPaid() — whether the bill for one order is cleared, used to gate result entry.
 *     The cashier allocates payments PER CODE (OpdGatingService::recordLinePayment writes one
 *     ar_activity row per charge line), so per-order "paid" is EXACT: read the native per-code
 *     A/R summary (InvoiceSummary::arGetInvoiceSummary, keyed by code) and an order is paid once
 *     every one of its codes is billed and has zero balance. (Phase 9.2 replaced the earlier
 *     waterfall heuristic, which existed only because payments used to be lump/unallocated.)
 *
 * Limitations (intentional, pilot-acceptable):
 *  - Order↔charge matching is by service code; the same code on two orders in one encounter is
 *    aggregated by code (both orders share that code's paid state).
 *  - A legacy LUMP payment (code='') is not allocated to any code, so it does not clear an order
 *    under this exact rule — all current cashier collection goes through recordLinePayment.
 *
 * Department routing: each result role may only result orders for its provider/department —
 * Lab Technicians -> Clinic Laboratory (ppid 1), Radiologists -> Clinic Radiology (ppid 2),
 * Physicians -> Clinic Procedures / minor theatre (ppid 3). admin/super -> all.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

namespace OpenEMR\Modules\OpdWorkflow\Service;

use OpenEMR\Billing\BillingUtilities;
use OpenEMR\Common\Acl\AclExtended;
use OpenEMR\Common\Acl\AclMain;

class OpdOrderBillingService
{
    const CODE_TYPE = 'HCPCS';      // code_types.ct_key for codes.code_type = 3
    const PRICE_LEVEL = 'standard';
    const PAID_EPSILON = 0.005;

    // Department providers (procedure_providers.ppid) by clinic.
    const DEPT_LAB = 1;             // Clinic Laboratory
    const DEPT_RADIOLOGY = 2;       // Clinic Radiology
    const DEPT_PROCEDURE = 3;       // Clinic Procedures (minor theatre)

    /**
     * ARO group title -> the department provider id(s) that role may result.
     * A user gets the union of their groups' departments.
     */
    const ROLE_DEPARTMENTS = [
        'Lab Technicians' => [self::DEPT_LAB],
        'Radiologists'    => [self::DEPT_RADIOLOGY],
        'Physicians'      => [self::DEPT_PROCEDURE],
    ];

    public function __construct()
    {
        // get_patient_balance() not needed here; charge math reuses prices/billing directly.
    }

    /* ---------------------------------------------------------------------
     * Billing: auto-charge ordered procedures
     * ------------------------------------------------------------------- */

    /**
     * Add the matching service-code charge(s) for an order onto its encounter.
     * Idempotent: a code already active on the encounter is left as-is.
     *
     * @return array<int,array{code:string,text:string,fee:string,added:bool}>
     */
    public function syncOrderCharges(int $orderId): array
    {
        $order = sqlQuery(
            "SELECT patient_id, encounter_id, provider_id FROM procedure_order WHERE procedure_order_id = ?",
            [$orderId]
        );
        if (!$order || empty($order['encounter_id'])) {
            return [];
        }
        $pid = (int)$order['patient_id'];
        $encounter = (int)$order['encounter_id'];
        $provider = (int)($order['provider_id'] ?? 0);

        $out = [];
        $seen = [];
        $codes = sqlStatement(
            "SELECT procedure_code FROM procedure_order_code
             WHERE procedure_order_id = ? AND procedure_code IS NOT NULL AND procedure_code != ''",
            [$orderId]
        );
        while ($c = sqlFetchArray($codes)) {
            $code = $c['procedure_code'];
            if (isset($seen[$code])) {
                continue; // de-dupe within the order
            }
            $seen[$code] = true;

            // Only bill codes that are real HCPCS service codes with our pricing. A procedure
            // code that isn't in `codes` (e.g. a raw external LOINC) is left unbilled rather
            // than creating a junk zero-fee line.
            $svc = sqlQuery("SELECT id FROM codes WHERE code = ? AND code_type = 3 LIMIT 1", [$code]);
            if (!$svc) {
                continue;
            }

            $already = sqlQuery(
                "SELECT id FROM billing WHERE encounter = ? AND code = ? AND code_type = ? AND activity = 1 LIMIT 1",
                [$encounter, $code, self::CODE_TYPE]
            );
            $out[] = $this->addCharge($encounter, $pid, $code, $provider, !$already);
        }
        return $out;
    }

    /**
     * Mirror of OpdVisitService::addCharge — look up code text + standard price, add the line.
     * @return array{code:string,text:string,fee:string,added:bool}
     */
    private function addCharge(int $encounter, int $pid, string $code, int $provider, bool $shouldAdd): array
    {
        $codeRow = sqlQuery(
            "SELECT c.code_text, p.pr_price
             FROM codes c
             LEFT JOIN prices p ON p.pr_id = c.id AND p.pr_selector = '' AND p.pr_level = ?
             WHERE c.code = ? AND c.code_type = 3 LIMIT 1",
            [self::PRICE_LEVEL, $code]
        );
        $text = $codeRow['code_text'] ?? $code;
        $fee = number_format((float)($codeRow['pr_price'] ?? 0), 2, '.', '');

        if ($shouldAdd) {
            BillingUtilities::addBilling(
                $encounter,
                self::CODE_TYPE,
                $code,
                $text,
                $pid,
                '1',                // authorized
                $provider,
                '',                 // modifier
                '1',                // units
                $fee,
                '',                 // ndc_info
                '',                 // justify
                0,                  // billed
                '',                 // notecodes
                self::PRICE_LEVEL
            );
        }
        return ['code' => $code, 'text' => $text, 'fee' => $fee, 'added' => $shouldAdd];
    }

    /* ---------------------------------------------------------------------
     * Payment gate: per-order paid state (waterfall over lump payments)
     * ------------------------------------------------------------------- */

    /**
     * Is the bill for this order cleared? False if the order has not been charged yet.
     */
    public function isOrderPaid(int $orderId): bool
    {
        $order = sqlQuery(
            "SELECT patient_id, encounter_id FROM procedure_order WHERE procedure_order_id = ?",
            [$orderId]
        );
        if (!$order || empty($order['encounter_id'])) {
            return false;
        }
        $pid = (int)$order['patient_id'];
        $encounter = (int)$order['encounter_id'];

        // The order's service codes.
        $codes = [];
        $res = sqlStatement(
            "SELECT DISTINCT procedure_code FROM procedure_order_code
             WHERE procedure_order_id = ? AND procedure_code IS NOT NULL AND procedure_code != ''",
            [$orderId]
        );
        while ($r = sqlFetchArray($res)) {
            $codes[] = $r['procedure_code'];
        }
        if (empty($codes)) {
            return false;
        }

        // Per-code charge/paid/balance from the native A/R summary (keyed by code, or code:modifier;
        // drug_sales as PROD:<id>). Now that the cashier allocates payments per code (recordLinePayment),
        // an order is paid exactly when each of its codes is billed and its balance is cleared.
        $inv = OpdGatingService::invoiceSummary($pid, $encounter);
        foreach ($codes as $code) {
            if (empty($inv[$code])) {
                return false; // this code not billed yet -> order not payable/paid
            }
            if ((float)($inv[$code]['bal'] ?? 0) > self::PAID_EPSILON) {
                return false; // outstanding balance on this code
            }
        }
        return true;
    }

    /* ---------------------------------------------------------------------
     * Department routing
     * ------------------------------------------------------------------- */

    /**
     * Provider/department ids the given user (default: current) may result.
     * Empty array means "none"; a wildcard is signalled by allowsAllDepartments().
     *
     * @return int[]
     */
    public function allowedDepartments(?string $user = null): array
    {
        $user = $user ?: ($_SESSION['authUser'] ?? '');
        if ($this->allowsAllDepartments($user)) {
            return [self::DEPT_LAB, self::DEPT_RADIOLOGY, self::DEPT_PROCEDURE];
        }
        $titles = AclExtended::aclGetGroupTitles($user) ?: [];
        $depts = [];
        foreach ($titles as $title) {
            foreach (self::ROLE_DEPARTMENTS[$title] ?? [] as $d) {
                $depts[$d] = true;
            }
        }
        return array_keys($depts);
    }

    public function allowsAllDepartments(?string $user = null): bool
    {
        $user = $user ?: ($_SESSION['authUser'] ?? '');
        return (bool)AclMain::aclCheckCore('admin', 'super', $user);
    }

    /**
     * May the user result this specific order (department check)?
     */
    public function canResultOrder(int $orderId, ?string $user = null): bool
    {
        if ($this->allowsAllDepartments($user)) {
            return true;
        }
        $order = sqlQuery("SELECT lab_id FROM procedure_order WHERE procedure_order_id = ?", [$orderId]);
        if (!$order) {
            return false;
        }
        return in_array((int)$order['lab_id'], $this->allowedDepartments($user), true);
    }
}
