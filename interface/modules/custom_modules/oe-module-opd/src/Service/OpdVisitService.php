<?php

/**
 * OPD Workflow — start-visit orchestration (reception).
 *
 * Given a patient + chosen clinic, creates today's appointment in that clinic
 * category, ensures today's encounter exists carrying the clinic (pc_catid),
 * registers the patient on the flow board at status RG (Registered / Awaiting
 * Cashier), and bills the Registration (first visit only) + clinic consultation
 * charges onto the encounter. Reuses native helpers — no schema changes.
 *
 * The patient becomes visible to the nurse only after the cashier marks the
 * visit paid (status PD); see OpdGatingService.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

namespace OpenEMR\Modules\OpdWorkflow\Service;

use OpenEMR\Billing\BillingUtilities;
use OpenEMR\Services\AppointmentService;

class OpdVisitService
{
    const STATUS_REGISTERED = 'RG';     // Registered (Awaiting Cashier)
    const STATUS_DISCHARGED = 'CM';     // Completed / Discharged (the only checkout status)
    const CONSULT_CODE_TYPE = 'HCPCS';  // code_types.ct_key for codes.code_type=3
    const PRICE_LEVEL = 'standard';

    // A visit is "active" until discharged (CM). These are the in-progress statuses.
    const ACTIVE_STATUSES = ['RG', 'PD', 'TR', 'WD', 'WL', 'LB', 'WP'];
    // Hygiene bound: a never-discharged visit older than this is treated as stale (not active),
    // so it neither blocks a new registration nor lingers in the queues. OPD visits resolve well
    // within this window.
    const ACTIVE_LOOKBACK_DAYS = 30;

    private OpdClinicService $clinicService;

    public function __construct()
    {
        require_once($GLOBALS['fileroot'] . '/library/forms.inc.php');
        require_once($GLOBALS['fileroot'] . '/library/encounter_events.inc.php');
        require_once($GLOBALS['fileroot'] . '/library/patient_tracker.inc.php');

        // encounter_events.inc.php sets a top-level `$today` only when included at
        // global scope. Required here from method scope it does not, leaving the
        // global `$today` empty — which makes todaysEncounterIf() match nothing and
        // create a duplicate encounter on every call. Set it explicitly.
        global $today;
        $today = date('Y-m-d');

        $this->clinicService = new OpdClinicService();
    }

    /**
     * Start (or resume) today's OPD visit for a patient in a clinic.
     *
     * @return array{encounter:int,eid:int,clinic:string,charges:array<int,array>,reused:bool}
     * @throws \RuntimeException on invalid clinic
     */
    public function startVisit(int $pid, int $clinicCatid, ?int $providerId = null): array
    {
        $cfg = $this->clinicService->getClinicConfig($clinicCatid);
        if (!$cfg || !$cfg['active']) {
            throw new \RuntimeException("Clinic is not configured for OPD (pc_catid=$clinicCatid).");
        }
        $cat = sqlQuery(
            "SELECT pc_catname, pc_duration FROM openemr_postcalendar_categories WHERE pc_catid = ?",
            [$clinicCatid]
        );
        if (!$cat) {
            throw new \RuntimeException("Clinic category not found (pc_catid=$clinicCatid).");
        }

        // Guard: a patient with an active (non-discharged) visit in this clinic must not be
        // re-registered — they continue the existing visit (e.g. returning for pending results).
        $active = $this->getActiveVisit($pid, $clinicCatid);
        if ($active) {
            return [
                'already_active' => true,
                'encounter' => $active['encounter'],
                'eid' => $active['eid'],
                'clinic' => $cat['pc_catname'],
                'status' => $active['status'],
                'status_label' => $active['status_label'],
                'started' => $active['started'],
                'charges' => [],
                'reused' => true,
            ];
        }

        $today = date('Y-m-d');
        $facilityId = (int)($GLOBALS['default_facility'] ?? 0);
        if (empty($facilityId)) {
            $facilityId = (int)(sqlQuery("SELECT id FROM facility WHERE service_location = 1 ORDER BY id LIMIT 1")['id'] ?? 0);
        }
        $duration = (int)($cat['pc_duration'] ?: 900);

        $apptService = new AppointmentService();

        // 1. Find or create today's appointment in this clinic category.
        $eid = $this->findTodaysAppointment($pid, $clinicCatid, $today);
        $reused = ($eid > 0);
        if (!$eid) {
            $eid = (int)$apptService->insert($pid, [
                'pc_catid' => $clinicCatid,
                'pc_title' => $cat['pc_catname'],
                'pc_duration' => $duration,
                'pc_hometext' => $cat['pc_catname'] . ' consultation',
                'pc_eventDate' => $today,
                'pc_apptstatus' => '-',
                'pc_startTime' => date('H:i:s'),
                'pc_facility' => $facilityId,
                'pc_billing_location' => $facilityId,
                'pc_aid' => $providerId ?: null,
            ]);
        } elseif ($providerId) {
            sqlStatement("UPDATE openemr_postcalendar_events SET pc_aid = ? WHERE pc_eid = ?", [$providerId, $eid]);
        }

        // 2. Ensure today's encounter exists, carrying the clinic (pc_catid).
        $encounter = (int)todaysEncounterCheck(
            $pid,
            $today,
            $cat['pc_catname'] . ' consultation',
            $facilityId,
            $facilityId,
            $providerId ?: '',
            $clinicCatid,
            true
        );

        // 3. Register on the flow board at RG (sets pc_apptstatus + tracker row).
        $apptService->updateAppointmentStatus($eid, self::STATUS_REGISTERED, $_SESSION['authUser'] ?? 'admin', $encounter);

        // 4. Bill charges onto the encounter (idempotent per code).
        $charges = $this->billVisit($encounter, $pid, $cfg, $providerId);

        return [
            'already_active' => false,
            'encounter' => $encounter,
            'eid' => $eid,
            'clinic' => $cat['pc_catname'],
            'charges' => $charges,
            'reused' => $reused,
        ];
    }

    /**
     * The active (non-discharged) visit for a patient in a clinic, or null.
     * @return array{eid:int,encounter:int,status:string,status_label:string,started:string}|null
     */
    public function getActiveVisit(int $pid, int $clinicCatid): ?array
    {
        $placeholders = implode(',', array_fill(0, count(self::ACTIVE_STATUSES), '?'));
        $binds = array_merge([$pid, $clinicCatid], self::ACTIVE_STATUSES, [self::ACTIVE_LOOKBACK_DAYS]);
        $row = sqlQuery(
            "SELECT e.pc_eid, e.pc_apptstatus, e.pc_eventDate, fe.encounter, lo.title AS status_label
             FROM openemr_postcalendar_events e
             LEFT JOIN form_encounter fe ON fe.pid = e.pc_pid AND fe.date = CONCAT(e.pc_eventDate, ' 00:00:00')
             LEFT JOIN list_options lo ON lo.list_id = 'apptstat' AND lo.option_id = e.pc_apptstatus
             WHERE e.pc_pid = ? AND e.pc_catid = ? AND e.pc_recurrtype = 0
                   AND e.pc_apptstatus IN ($placeholders)
                   AND e.pc_eventDate >= (CURDATE() - INTERVAL ? DAY)
             ORDER BY e.pc_eventDate DESC, e.pc_eid DESC LIMIT 1",
            $binds
        );
        if (!$row || empty($row['encounter'])) {
            return null;
        }
        return [
            'eid' => (int)$row['pc_eid'],
            'encounter' => (int)$row['encounter'],
            'status' => $row['pc_apptstatus'],
            'status_label' => $row['status_label'] ?? $row['pc_apptstatus'],
            'started' => substr((string)$row['pc_eventDate'], 0, 10),
        ];
    }

    /**
     * All active (non-discharged) visits for a patient across clinics — for display.
     * @return array<int,array{clinic:string,status:string,status_label:string,started:string,encounter:int}>
     */
    public function getActiveVisits(int $pid): array
    {
        $placeholders = implode(',', array_fill(0, count(self::ACTIVE_STATUSES), '?'));
        $binds = array_merge([$pid], self::ACTIVE_STATUSES, [self::ACTIVE_LOOKBACK_DAYS]);
        $rows = [];
        $res = sqlStatement(
            "SELECT cat.pc_catname, e.pc_apptstatus, e.pc_eventDate, fe.encounter, lo.title AS status_label
             FROM openemr_postcalendar_events e
             JOIN openemr_postcalendar_categories cat ON cat.pc_catid = e.pc_catid AND cat.pc_constant_id LIKE 'clinic_%'
             LEFT JOIN form_encounter fe ON fe.pid = e.pc_pid AND fe.date = CONCAT(e.pc_eventDate, ' 00:00:00')
             LEFT JOIN list_options lo ON lo.list_id = 'apptstat' AND lo.option_id = e.pc_apptstatus
             WHERE e.pc_pid = ? AND e.pc_recurrtype = 0
                   AND e.pc_apptstatus IN ($placeholders)
                   AND e.pc_eventDate >= (CURDATE() - INTERVAL ? DAY)
             ORDER BY e.pc_eventDate DESC",
            $binds
        );
        while ($r = sqlFetchArray($res)) {
            $rows[] = [
                'clinic' => $r['pc_catname'],
                'status' => $r['pc_apptstatus'],
                'status_label' => $r['status_label'] ?? $r['pc_apptstatus'],
                'started' => substr((string)$r['pc_eventDate'], 0, 10),
                'encounter' => (int)($r['encounter'] ?? 0),
            ];
        }
        return $rows;
    }

    /**
     * Discharge an active visit (ends the lifecycle): set status CM, which drives the native
     * checkout + flow-board roll-off. After this the patient leaves the queues and reception may
     * start a fresh visit.
     */
    public function discharge(int $eid, int $encounter): void
    {
        $this->setStatus($eid, self::STATUS_DISCHARGED, $encounter);
        sqlStatement("UPDATE form_encounter SET date_end = NOW() WHERE encounter = ?", [$encounter]);
    }

    /**
     * Advance an appointment to a new status (updates the flow board too).
     */
    public function setStatus(int $eid, string $status, int $encounter): void
    {
        (new AppointmentService())->updateAppointmentStatus($eid, $status, $_SESSION['authUser'] ?? 'admin', $encounter);
    }

    /**
     * Assign/route the visit to a doctor (appointment provider + encounter provider).
     */
    public function assignProvider(int $eid, int $encounter, int $providerId): void
    {
        sqlStatement("UPDATE openemr_postcalendar_events SET pc_aid = ? WHERE pc_eid = ?", [$providerId, $eid]);
        sqlStatement("UPDATE form_encounter SET provider_id = ? WHERE encounter = ?", [$providerId, $encounter]);
    }

    /**
     * @return int pc_eid of today's appointment in this clinic, or 0
     */
    private function findTodaysAppointment(int $pid, int $clinicCatid, string $today): int
    {
        $row = sqlQuery(
            "SELECT pc_eid FROM openemr_postcalendar_events
             WHERE pc_pid = ? AND pc_catid = ? AND pc_eventDate = ? AND pc_recurrtype = 0
             ORDER BY pc_eid DESC LIMIT 1",
            [$pid, $clinicCatid, $today]
        );
        return (int)($row['pc_eid'] ?? 0);
    }

    /**
     * Bill Registration (first visit ever) + clinic consultation onto the encounter.
     * Idempotent: skips a code already active on this encounter / a REG ever billed.
     *
     * @return array<int,array{code:string,text:string,fee:string,added:bool}>
     */
    private function billVisit(int $encounter, int $pid, array $cfg, ?int $providerId): array
    {
        $out = [];
        $provider = $providerId ?: 0;

        // Registration — once per patient lifetime.
        if (!empty($cfg['requires_registration'])) {
            $regCode = $cfg['registration_code'];
            $everBilled = sqlQuery(
                "SELECT id FROM billing WHERE pid = ? AND code = ? AND activity = 1 LIMIT 1",
                [$pid, $regCode]
            );
            $out[] = $this->addCharge($encounter, $pid, $regCode, $provider, !$everBilled);
        }

        // Consultation — once per encounter.
        $consCode = $cfg['consultation_code'];
        $onEncounter = sqlQuery(
            "SELECT id FROM billing WHERE encounter = ? AND code = ? AND activity = 1 LIMIT 1",
            [$encounter, $consCode]
        );
        $out[] = $this->addCharge($encounter, $pid, $consCode, $provider, !$onEncounter);

        return $out;
    }

    /**
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
                self::CONSULT_CODE_TYPE,
                $code,
                $text,
                $pid,
                '1',              // authorized
                $provider,
                '',               // modifier
                '1',              // units
                $fee,
                '',               // ndc_info
                '',               // justify
                0,                // billed
                '',               // notecodes
                self::PRICE_LEVEL
            );
        }

        return ['code' => $code, 'text' => $text, 'fee' => $fee, 'added' => $shouldAdd];
    }
}
