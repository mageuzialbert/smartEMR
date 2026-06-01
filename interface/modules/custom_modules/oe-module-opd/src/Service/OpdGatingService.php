<?php

/**
 * OPD Workflow — payment gating (cashier).
 *
 * Derives whether a visit's Registration + Consultation charges are paid, and
 * advances the appointment to PD (Paid / Awaiting Triage) once they are. The
 * triage and doctor queues key off this, so an unpaid patient never reaches the
 * nurse.
 *
 * "Paid" is derived from the encounter balance (billing − ar_activity − drug_sales),
 * the same figure the native billing/AR screens show — see get_patient_balance().
 * Payment posting mirrors the native front-desk patient payment in
 * interface/patient_file/front_payment.php (ar_session + ar_activity account_code
 * 'PCP' + a payments-table receipt), so accounting stays consistent.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

namespace OpenEMR\Modules\OpdWorkflow\Service;

use OpenEMR\Billing\InvoiceSummary;
use OpenEMR\Services\AppointmentService;

class OpdGatingService
{
    const STATUS_REGISTERED = 'RG'; // Registered (Awaiting Cashier)
    const STATUS_PAID = 'PD';       // Paid (Awaiting Triage)
    const PAID_EPSILON = 0.005;

    public function __construct()
    {
        require_once($GLOBALS['fileroot'] . '/library/patient.inc.php');  // get_patient_balance()
        require_once($GLOBALS['fileroot'] . '/library/payment.inc.php');  // frontPayment()
    }

    /**
     * Per-code A/R summary (chg/bal/adj/paid keyed by code; drug_sales as PROD:<id>) — the shared
     * source for every OPD per-line paid check (orders + meds + the cashier bill). Wrapped to mute
     * native InvoiceSummary's pre-existing PHP-8 "undefined array key" notices on product-sale rows
     * (it does `+=` on an uninitialised PROD key); the computed figures are correct.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function invoiceSummary(int $pid, int $encounter): array
    {
        $prev = error_reporting();
        error_reporting($prev & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
        try {
            return InvoiceSummary::arGetInvoiceSummary($pid, $encounter, true) ?: [];
        } finally {
            error_reporting($prev);
        }
    }

    /**
     * Outstanding balance for one encounter (charges − payments − drug sales).
     */
    public function getVisitBalance(int $pid, int $encounter): float
    {
        return (float) get_patient_balance($pid, false, $encounter);
    }

    public function isVisitPaid(int $pid, int $encounter): bool
    {
        return $this->getVisitBalance($pid, $encounter) <= self::PAID_EPSILON;
    }

    /**
     * Total outstanding balance across all of a patient's encounters (for reception's
     * "outstanding bills" view — no financial ACL needed).
     */
    public function getPatientBalance(int $pid): float
    {
        return (float) get_patient_balance($pid, false);
    }

    /**
     * Active (non-deleted) billing lines on the encounter, for display.
     * @return array<int,array{code:string,code_text:string,fee:float}>
     */
    public function getEncounterCharges(int $encounter): array
    {
        $rows = [];
        $res = sqlStatement(
            "SELECT code, code_text, fee FROM billing WHERE encounter = ? AND activity = 1 ORDER BY id",
            [$encounter]
        );
        while ($r = sqlFetchArray($res)) {
            $rows[] = ['code' => $r['code'], 'code_text' => $r['code_text'], 'fee' => (float)$r['fee']];
        }
        return $rows;
    }

    /**
     * Record a patient payment against the encounter (mirrors native front payment).
     * Returns the ar_session id.
     */
    public function recordPayment(int $pid, int $encounter, string $method, float $amount, string $source = ''): int
    {
        if ($amount <= 0) {
            throw new \RuntimeException('Payment amount must be greater than zero.');
        }
        $userId = (int)($_SESSION['authUserID'] ?? 0);
        $timestamp = date('Y-m-d H:i:s');

        $sessionId = (int) sqlInsert(
            "INSERT INTO ar_session
               (payer_id, user_id, reference, check_date, deposit_date, pay_total, global_amount,
                payment_type, description, patient_id, payment_method, adjustment_code, post_to_date)
             VALUES ('0', ?, ?, now(), now(), ?, '', 'patient', 'OPD consultation', ?, ?, 'patient_payment', now())",
            [$userId, $source, $amount, $pid, $method]
        );

        sqlBeginTrans();
        $seq = sqlQuery(
            "SELECT IFNULL(MAX(sequence_no),0) + 1 AS increment FROM ar_activity WHERE pid = ? AND encounter = ?",
            [$pid, $encounter]
        );
        sqlInsert(
            "INSERT INTO ar_activity
               (pid, encounter, sequence_no, code_type, code, modifier, payer_type, post_time, post_user,
                session_id, pay_amount, account_code)
             VALUES (?, ?, ?, '', '', '', 0, now(), ?, ?, ?, 'PCP')",
            [$pid, $encounter, $seq['increment'], $userId, $sessionId, $amount]
        );
        sqlCommitTrans();

        // Receipt log (front receipts report + payment-method tracking).
        frontPayment($pid, $encounter, $method, $source, $amount, 0, $timestamp);

        return $sessionId;
    }

    /**
     * Record a patient payment allocated PER CHARGE LINE (per code), so a specific order's bill
     * can be cleared on its own and that department unlocked for results. Mirrors recordPayment
     * but writes one ar_activity row per allocation (with its code_type/code/modifier), all under
     * one ar_session, plus a single payments-table receipt for the method/total.
     *
     * @param array<int,array{code_type:string,code:string,modifier?:string,amount:float}> $allocations
     * @return int the ar_session id
     */
    public function recordLinePayment(int $pid, int $enc, array $allocations, string $method, string $source = 'OPD cashier'): int
    {
        $lines = [];
        $total = 0.0;
        foreach ($allocations as $a) {
            $amt = (float)($a['amount'] ?? 0);
            if ($amt <= 0 || empty($a['code'])) {
                continue;
            }
            $lines[] = [
                'code_type' => (string)($a['code_type'] ?? ''),
                'code' => (string)$a['code'],
                'modifier' => (string)($a['modifier'] ?? ''),
                'amount' => $amt,
            ];
            $total += $amt;
        }
        if (empty($lines)) {
            throw new \RuntimeException('No payment lines to record.');
        }

        $userId = (int)($_SESSION['authUserID'] ?? 0);
        $timestamp = date('Y-m-d H:i:s');

        $sessionId = (int) sqlInsert(
            "INSERT INTO ar_session
               (payer_id, user_id, reference, check_date, deposit_date, pay_total, global_amount,
                payment_type, description, patient_id, payment_method, adjustment_code, post_to_date)
             VALUES ('0', ?, ?, now(), now(), ?, '', 'patient', 'OPD per-item payment', ?, ?, 'patient_payment', now())",
            [$userId, $source, $total, $pid, $method]
        );

        sqlBeginTrans();
        try {
            foreach ($lines as $ln) {
                $seq = sqlQuery(
                    "SELECT IFNULL(MAX(sequence_no),0) + 1 AS increment FROM ar_activity WHERE pid = ? AND encounter = ?",
                    [$pid, $enc]
                );
                sqlInsert(
                    "INSERT INTO ar_activity
                       (pid, encounter, sequence_no, code_type, code, modifier, payer_type, post_time, post_user,
                        session_id, pay_amount, account_code)
                     VALUES (?, ?, ?, ?, ?, ?, 0, now(), ?, ?, ?, 'PCP')",
                    [$pid, $enc, $seq['increment'], $ln['code_type'], $ln['code'], $ln['modifier'], $userId, $sessionId, $ln['amount']]
                );
            }
            sqlCommitTrans();
        } catch (\Throwable $e) {
            sqlRollbackTrans();
            throw $e;
        }

        // Single receipt for the method/total (front receipts report + payment-method tracking).
        frontPayment($pid, $enc, $method, $source, $total, 0, $timestamp);

        return $sessionId;
    }

    /**
     * If the visit is fully paid and still at RG, advance it to PD (Awaiting Triage).
     * @return bool whether the visit is paid (and thus active for triage)
     */
    public function activateIfPaid(int $eid, int $pid, int $encounter): bool
    {
        if (!$this->isVisitPaid($pid, $encounter)) {
            return false;
        }
        $appt = sqlQuery("SELECT pc_apptstatus FROM openemr_postcalendar_events WHERE pc_eid = ?", [$eid]);
        if (($appt['pc_apptstatus'] ?? '') === self::STATUS_REGISTERED) {
            $apptService = new AppointmentService();
            $apptService->updateAppointmentStatus($eid, self::STATUS_PAID, $_SESSION['authUser'] ?? 'admin', $encounter);
        }
        return true;
    }
}
