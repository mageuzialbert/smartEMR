<?php

/**
 * OPD Workflow — fast multi-row prescribing.
 *
 * Backs the simplified prescription grid (public/prescribe.php). Each row is one drug:
 * dose × frequency-per-day × days → quantity, written as a standard `prescriptions` row so the
 * existing pharmacy chain (OpdPharmacyService: queue → confirm → drug_sales → cashier → dispense)
 * works unchanged. Stock is deducted at DISPENSE (after payment), never here.
 *
 * Persistence reuses the native library/classes/Prescription.class.php (handles uuid, txDate,
 * usage_category/request_intent titles and other NOT-NULL/no-default columns).
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

namespace OpenEMR\Modules\OpdWorkflow\Service;

class OpdPrescribeService
{
    /**
     * Clinic frequency set → per-day multiplier + the native drug_interval option_id (for storage/
     * native display). PRN has no multiplier (quantity entered manually).
     */
    const FREQUENCIES = [
        'OD'    => ['label' => 'OD — once a day',      'per_day' => 1,    'interval' => 9],
        'BD'    => ['label' => 'BD — twice a day',     'per_day' => 2,    'interval' => 1],
        'TDS'   => ['label' => 'TDS — 3 times a day',  'per_day' => 3,    'interval' => 2],
        'QDS'   => ['label' => 'QDS — 4 times a day',  'per_day' => 4,    'interval' => 3],
        'NOCTE' => ['label' => 'NOCTE — at night',     'per_day' => 1,    'interval' => 16],
        'PRN'   => ['label' => 'PRN — as needed',      'per_day' => null, 'interval' => 17],
        'STAT'  => ['label' => 'STAT — immediately',   'per_day' => 1,    'interval' => 18],
    ];

    /** In-house drug catalogue for the picker: [['drug_id','name','form'], ...]. */
    public function drugCatalogue(): array
    {
        $rows = [];
        $res = sqlStatement(
            "SELECT drug_id, name, form FROM drugs WHERE drug_id > 0 AND (active IS NULL OR active = 1) ORDER BY name"
        );
        while ($r = sqlFetchArray($res)) {
            $rows[] = ['drug_id' => (int)$r['drug_id'], 'name' => $r['name'], 'form' => (int)$r['form']];
        }
        return $rows;
    }

    /** drug_form options (the dispensing "unit"): [option_id => title]. */
    public function unitOptions(): array
    {
        $out = [];
        $res = sqlStatement("SELECT option_id, title FROM list_options WHERE list_id = 'drug_form' AND activity = 1 ORDER BY seq, title");
        while ($r = sqlFetchArray($res)) {
            $out[(string)$r['option_id']] = $r['title'];
        }
        return $out;
    }

    /**
     * Active prescriptions already on this visit (for display + remove). Flags whether a pharmacy
     * charge (drug_sales) already exists, in which case it can no longer be removed by the doctor.
     * @return array<int,array<string,mixed>>
     */
    public function listForEncounter(int $pid, int $encounter): array
    {
        $units = $this->unitOptions();
        $freqByInterval = [];
        foreach (self::FREQUENCIES as $code => $f) {
            $freqByInterval[$f['interval']] = $code;
        }
        $rows = [];
        $res = sqlStatement(
            "SELECT p.id, p.drug, p.dosage, p.quantity, p.form, p.interval, p.note,
                    (SELECT COUNT(*) FROM drug_sales s WHERE s.prescription_id = p.id) AS at_pharmacy
             FROM prescriptions p
             WHERE p.patient_id = ? AND p.encounter = ? AND p.active = 1
             ORDER BY p.id",
            [$pid, $encounter]
        );
        while ($r = sqlFetchArray($res)) {
            $rows[] = [
                'id' => (int)$r['id'],
                'drug' => $r['drug'],
                'dosage' => $r['dosage'],
                'quantity' => $r['quantity'],
                'unit' => $units[(string)$r['form']] ?? '',
                'freq' => $freqByInterval[(int)$r['interval']] ?? '',
                'note' => $r['note'],
                'at_pharmacy' => (int)$r['at_pharmacy'] > 0,
            ];
        }
        return $rows;
    }

    /**
     * Persist the grid rows as prescriptions. Quantity is recomputed server-side from
     * dose × per_day × days (PRN / no-multiplier uses the posted quantity). Returns rows saved.
     *
     * @param array<int,array{drug_id:int,dose:string,unit:string,freq:string,days:string,qty:string,size:string}> $rows
     */
    public function save(int $pid, int $encounter, int $providerId, array $rows): int
    {
        require_once($GLOBALS['fileroot'] . '/library/classes/Prescription.class.php');
        $units = $this->unitOptions();
        $saved = 0;

        foreach ($rows as $row) {
            $drugId = (int)($row['drug_id'] ?? 0);
            if ($drugId <= 0) {
                continue; // not a catalogue drug
            }
            $dose = trim((string)($row['dose'] ?? ''));
            $freqCode = (string)($row['freq'] ?? '');
            $days = (int)($row['days'] ?? 0);
            $unitOpt = (string)($row['unit'] ?? '');
            $freq = self::FREQUENCIES[$freqCode] ?? null;

            // Quantity: auto from dose × per_day × days when the frequency has a multiplier; else posted.
            $qty = (int)($row['qty'] ?? 0);
            if ($freq && $freq['per_day'] !== null && is_numeric($dose) && $days > 0) {
                $qty = (int)ceil((float)$dose * (int)$freq['per_day'] * $days);
            }
            if ($qty <= 0) {
                continue;
            }

            $drugRow = sqlQuery("SELECT name, size FROM drugs WHERE drug_id = ?", [$drugId]);
            $name = $drugRow['name'] ?? ('#' . $drugId);
            $unitLabel = $units[$unitOpt] ?? '';
            $noteBits = array_filter([
                $dose !== '' ? $dose : null,
                $unitLabel !== '' ? $unitLabel : null,
                $freqCode !== '' ? $freqCode : null,
                $days > 0 ? ('× ' . $days . ' ' . xl('days')) : null,
            ]);

            $p = new \Prescription(0);
            $p->set_patient_id($pid);
            $p->set_encounter($encounter);
            if ($providerId > 0) {
                $p->set_provider_id($providerId);
            }
            $p->set_drug_id($drugId);
            $p->set_drug($name);
            $p->set_dosage($dose);
            $p->set_quantity((string)$qty);
            if (is_numeric($unitOpt)) {
                $p->set_form((int)$unitOpt);
            }
            if ($freq) {
                $p->set_interval((int)$freq['interval']);
            }
            if (($drugRow['size'] ?? '') !== '') {
                $p->set_size((string)$drugRow['size']);
            }
            $p->set_note(implode(' ', $noteBits));
            $p->set_active(1);
            $p->set_start_date(date('Y-m-d'));
            $p->persist();
            $saved++;
        }
        return $saved;
    }

    /** Remove a prescription (archive) — only if it has not reached the pharmacy yet. */
    public function archive(int $rxId): void
    {
        $atPharmacy = sqlQuery("SELECT COUNT(*) AS c FROM drug_sales WHERE prescription_id = ?", [$rxId]);
        if ((int)($atPharmacy['c'] ?? 0) > 0) {
            throw new \RuntimeException('This medicine is already at the pharmacy and cannot be removed here.');
        }
        sqlStatement("UPDATE prescriptions SET active = 0, date_modified = NOW() WHERE id = ?", [$rxId]);
    }
}
