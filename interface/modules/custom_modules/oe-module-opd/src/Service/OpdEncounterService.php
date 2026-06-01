<?php

/**
 * OPD Workflow — encounter-view aggregation.
 *
 * Surfaces the one piece the native encounter summary does not show on its own:
 * in-house medications dispensed against the encounter (drug_sales). HPI, history,
 * PMH, examination, provisional/final diagnosis and investigations live in the OPD
 * LBF + Procedure Orders, which the native summary already renders.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

namespace OpenEMR\Modules\OpdWorkflow\Service;

class OpdEncounterService
{
    /**
     * Medications dispensed from the in-house pharmacy for an encounter.
     * @return array<int,array{name:string,selector:string,quantity:int,fee:float,sale_date:string}>
     */
    public function getDispensedMeds(int $encounter): array
    {
        $rows = [];
        $res = sqlStatement(
            // Only truly dispensed rows (inventory_id > 0). A pending OPD charge created at
            // pharmacist-confirm time carries inventory_id = 0 and is not yet handed over, so it
            // must not appear as "dispensed" on the encounter summary.
            "SELECT ds.quantity, ds.fee, ds.sale_date, ds.selector, d.name
             FROM drug_sales ds
             LEFT JOIN drugs d ON d.drug_id = ds.drug_id
             WHERE ds.encounter = ? AND ds.drug_id > 0 AND ds.inventory_id > 0
             ORDER BY ds.sale_date, ds.sale_id",
            [$encounter]
        );
        while ($r = sqlFetchArray($res)) {
            $rows[] = [
                'name' => $r['name'] ?? '',
                'selector' => $r['selector'] ?? '',
                'quantity' => (int)$r['quantity'],
                'fee' => (float)$r['fee'],
                'sale_date' => $r['sale_date'] ?? '',
            ];
        }
        return $rows;
    }

    /**
     * Whether the given doctor may EDIT this encounter (otherwise view-only).
     *
     * Rule (OPD pilot): a doctor may edit only encounters where they are the
     * provider, and only while the encounter is EITHER under one day old OR
     * still open (not discharged). Once it is both older than a day AND closed
     * (discharged — form_encounter.date_end set), it becomes view-only.
     */
    public function isEncounterEditableByDoctor(int $encounter, int $userId): bool
    {
        $row = sqlQuery(
            "SELECT provider_id, date, date_end FROM form_encounter WHERE encounter = ?",
            [$encounter]
        );
        if (empty($row)) {
            return false;
        }
        // Ownership: only the encounter's provider may edit.
        if ((int)($row['provider_id'] ?? 0) !== $userId) {
            return false;
        }
        $lessThanOneDayOld = (time() - strtotime((string)$row['date'])) < 86400;
        $closed = !empty($row['date_end'])
            && $row['date_end'] !== '0000-00-00 00:00:00';
        // OR logic: editable while still fresh OR still open.
        return $lessThanOneDayOld || !$closed;
    }
}
