<?php

/**
 * OPD Workflow — pharmacy: confirm-dispensable → bill → pay → dispense.
 *
 * Flow:
 *   1. Doctor prescribes (native prescriptions row on the encounter).
 *   2. Pharmacist CONFIRMS a prescription is dispensable (in stock) — this creates a pending
 *      charge: a drug_sales row with inventory_id = 0 (NO stock deducted yet), fee = the sale
 *      price. The charge shows on the cashier's per-line bill as PROD:<drug_id>.
 *   3. Cashier collects that line (per-code payment → ar_activity code 'PROD:<drug_id>').
 *   4. Pharmacist DISPENSES — only once the line is paid: pick a lot (FEFO), decrement on_hand,
 *      and stamp the drug_sales row with the real inventory_id (pending → dispensed).
 *
 * The drug_sales row is the single record for both the charge and the eventual dispense:
 *   inventory_id = 0  → pending (charge only, not handed over)
 *   inventory_id > 0  → dispensed from that lot
 * "Paid" is read from the native per-code A/R summary (same source as orders), so the cashier's
 * per-line collection unlocks dispensing exactly.
 *
 * Pilot notes: most catalogue drugs have no `prices` row, so the pharmacist sets/confirms the fee
 * at confirmation (pre-filled from `prices` when present). Two prescriptions for the same drug_id
 * in one encounter share that code's paid state (A/R aggregates by code). Dispense uses a single
 * FEFO lot with enough on_hand (no split-lot).
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

namespace OpenEMR\Modules\OpdWorkflow\Service;

use OpenEMR\Common\Uuid\UuidRegistry;

class OpdPharmacyService
{
    const PRICE_LEVEL = 'standard';
    const PAID_EPSILON = 0.005;
    const LOOKBACK_DAYS = 30;

    /** Catalogue sale price for a drug (standard level), or null if unpriced. */
    public function catalogPrice(int $drugId): ?float
    {
        $row = sqlQuery(
            "SELECT pr_price FROM prices WHERE pr_id = ? AND pr_selector = '' AND pr_level = ? LIMIT 1",
            [$drugId, self::PRICE_LEVEL]
        );
        return isset($row['pr_price']) ? (float)$row['pr_price'] : null;
    }

    /** Total usable on-hand stock for a drug (non-destroyed, unexpired lots). */
    public function stockOnHand(int $drugId): int
    {
        $row = sqlQuery(
            "SELECT IFNULL(SUM(on_hand),0) AS oh FROM drug_inventory
             WHERE drug_id = ? AND destroy_date IS NULL
                   AND (expiration IS NULL OR expiration > CURDATE())",
            [$drugId]
        );
        return (int)($row['oh'] ?? 0);
    }

    /**
     * Pharmacist worklist: active-visit prescriptions with their pharmacy status.
     * @return array<int,array<string,mixed>>
     */
    public function getQueue(): array
    {
        $rows = [];
        $res = sqlStatement(
            "SELECT p.id AS rx_id, p.patient_id, p.encounter, p.drug_id, p.drug, p.quantity,
                    pd.fname, pd.lname, pd.pubpid,
                    s.sale_id, s.inventory_id, s.fee
             FROM prescriptions p
             JOIN patient_data pd ON pd.pid = p.patient_id
             JOIN form_encounter fe ON fe.encounter = p.encounter
             LEFT JOIN drug_sales s ON s.prescription_id = p.id
             WHERE p.active = 1
                   AND fe.date >= (CURDATE() - INTERVAL ? DAY)
             ORDER BY p.patient_id, p.id",
            [self::LOOKBACK_DAYS]
        );
        while ($r = sqlFetchArray($res)) {
            $drugId = (int)$r['drug_id'];
            $saleId = $r['sale_id'] !== null ? (int)$r['sale_id'] : 0;
            $invId = (int)($r['inventory_id'] ?? 0);

            if ($drugId <= 0) {
                $status = 'manual';            // free-text drug, no catalogue/inventory link
            } elseif ($saleId === 0) {
                $status = 'to_confirm';
            } elseif ($invId > 0) {
                $status = 'dispensed';
            } elseif ($this->isMedPaid($saleId)) {
                $status = 'ready';
            } else {
                $status = 'awaiting_payment';
            }

            $rows[] = [
                'rx_id' => (int)$r['rx_id'],
                'pid' => (int)$r['patient_id'],
                'encounter' => (int)$r['encounter'],
                'drug_id' => $drugId,
                'drug_name' => $r['drug'] !== '' ? $r['drug'] : ('#' . $drugId),
                'quantity' => (int)$r['quantity'],
                'patient' => $r['lname'] . ', ' . $r['fname'],
                'pubpid' => $r['pubpid'],
                'sale_id' => $saleId,
                'fee' => $saleId ? (float)$r['fee'] : (float)($this->catalogPrice($drugId) ?? 0),
                'catalog_price' => $this->catalogPrice($drugId),
                'on_hand' => $drugId > 0 ? $this->stockOnHand($drugId) : 0,
                'status' => $status,
            ];
        }
        return $rows;
    }

    /**
     * Confirm a prescription is dispensable: create the pending charge (drug_sales, inventory_id=0).
     * Idempotent — if a sale already exists for this prescription, returns it unchanged.
     * @return int sale_id
     */
    public function confirmPrescription(int $rxId, float $fee): int
    {
        $rx = sqlQuery(
            "SELECT patient_id, encounter, drug_id, quantity FROM prescriptions WHERE id = ?",
            [$rxId]
        );
        if (!$rx || (int)$rx['drug_id'] <= 0) {
            throw new \RuntimeException('Prescription is not linked to a catalogue drug.');
        }
        $existing = sqlQuery("SELECT sale_id FROM drug_sales WHERE prescription_id = ? LIMIT 1", [$rxId]);
        if (!empty($existing['sale_id'])) {
            return (int)$existing['sale_id'];
        }
        if ($fee < 0) {
            $fee = 0.0;
        }
        $pid = (int)$rx['patient_id'];
        $enc = (int)$rx['encounter'];
        $drugId = (int)$rx['drug_id'];
        $qty = max(1, (int)$rx['quantity']);
        $user = $_SESSION['authUser'] ?? 'admin';
        $userId = (int)($_SESSION['authUserID'] ?? 0);

        // Mirrors DrugSalesService non-dispensable insert: inventory_id = 0 (no stock movement).
        $uuid = UuidRegistry::getRegistryForTable('drug_sales')->createUuid();
        $saleId = (int) sqlInsert(
            "INSERT INTO drug_sales
               (uuid, drug_id, inventory_id, prescription_id, pid, encounter, user, sale_date,
                quantity, fee, pricelevel, created_by, updated_by)
             VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$uuid, $drugId, $rxId, $pid, $enc, $user, date('Y-m-d'), $qty, number_format($fee, 2, '.', ''),
                self::PRICE_LEVEL, $userId, $userId]
        );
        return $saleId;
    }

    /** Is the charge for this pending sale cleared at the cashier? (per-code A/R, exact). */
    public function isMedPaid(int $saleId): bool
    {
        $sale = sqlQuery("SELECT pid, encounter, drug_id FROM drug_sales WHERE sale_id = ?", [$saleId]);
        if (!$sale) {
            return false;
        }
        $key = 'PROD:' . (int)$sale['drug_id'];
        $inv = OpdGatingService::invoiceSummary((int)$sale['pid'], (int)$sale['encounter']);
        if (empty($inv[$key])) {
            return false;
        }
        return (float)($inv[$key]['bal'] ?? 0) <= self::PAID_EPSILON;
    }

    /**
     * Dispense a confirmed + paid medication: pick a FEFO lot with enough stock, decrement it,
     * and stamp the sale with the real inventory_id (pending → dispensed). Hard-gated on payment.
     */
    public function dispense(int $saleId): void
    {
        $sale = sqlQuery(
            "SELECT pid, encounter, drug_id, quantity, inventory_id FROM drug_sales WHERE sale_id = ?",
            [$saleId]
        );
        if (!$sale) {
            throw new \RuntimeException('Sale not found.');
        }
        if ((int)$sale['inventory_id'] > 0) {
            return; // already dispensed
        }
        if (!$this->isMedPaid($saleId)) {
            throw new \RuntimeException('Cannot dispense — the patient has not paid for this medication.');
        }
        $drugId = (int)$sale['drug_id'];
        $qty = max(1, (int)$sale['quantity']);

        // FEFO: earliest-expiring lot that can satisfy the whole quantity from one lot.
        $lot = sqlQuery(
            "SELECT inventory_id FROM drug_inventory
             WHERE drug_id = ? AND on_hand >= ? AND destroy_date IS NULL
                   AND (expiration IS NULL OR expiration > CURDATE())
             ORDER BY (expiration IS NULL), expiration ASC, inventory_id ASC LIMIT 1",
            [$drugId, $qty]
        );
        if (empty($lot['inventory_id'])) {
            throw new \RuntimeException('Insufficient stock in a single lot to dispense this quantity.');
        }
        $lotId = (int)$lot['inventory_id'];

        sqlBeginTrans();
        try {
            sqlStatement("UPDATE drug_inventory SET on_hand = on_hand - ? WHERE inventory_id = ?", [$qty, $lotId]);
            sqlStatement(
                "UPDATE drug_sales SET inventory_id = ?, billed = 1, bill_date = NOW() WHERE sale_id = ?",
                [$lotId, $saleId]
            );
            sqlCommitTrans();
        } catch (\Throwable $e) {
            sqlRollbackTrans();
            throw $e;
        }
    }
}
