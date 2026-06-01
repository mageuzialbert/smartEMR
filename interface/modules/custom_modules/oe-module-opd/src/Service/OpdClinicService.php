<?php

/**
 * OPD Workflow — clinic configuration access.
 *
 * Clinics are patient-appointment categories (openemr_postcalendar_categories,
 * pc_cattype=0). opd_clinic_config maps each clinic to its consultation fee code;
 * opd_provider_clinic records which doctors serve which clinics.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

namespace OpenEMR\Modules\OpdWorkflow\Service;

class OpdClinicService
{
    /**
     * All active clinics with their consultation fee code + price.
     * @return array<int,array> keyed list of clinics
     */
    public function getClinics(): array
    {
        $sql = "SELECT cat.pc_catid, cat.pc_catname, cat.pc_constant_id,
                       cfg.consultation_code, cfg.registration_code, cfg.requires_registration,
                       cons.code_text AS consultation_text, pr.pr_price AS consultation_fee
                FROM openemr_postcalendar_categories cat
                JOIN opd_clinic_config cfg ON cfg.pc_catid = cat.pc_catid AND cfg.active = 1
                LEFT JOIN codes cons ON cons.code = cfg.consultation_code AND cons.code_type = 3
                LEFT JOIN prices pr ON pr.pr_id = cons.id AND pr.pr_selector = '' AND pr.pr_level = 'standard'
                WHERE cat.pc_active = 1
                ORDER BY cat.pc_seq, cat.pc_catname";
        $rows = [];
        $res = sqlStatement($sql);
        while ($row = sqlFetchArray($res)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Config row for one clinic, or null.
     */
    public function getClinicConfig(int $pcCatid): ?array
    {
        $row = sqlQuery(
            "SELECT pc_catid, consultation_code, registration_code, requires_registration, active
             FROM opd_clinic_config WHERE pc_catid = ?",
            [$pcCatid]
        );
        return $row ?: null;
    }

    /**
     * pc_catid list of clinics a provider is granted.
     * @return int[]
     */
    public function getProviderClinics(int $providerId): array
    {
        $ids = [];
        $res = sqlStatement(
            "SELECT pc_catid FROM opd_provider_clinic WHERE provider_id = ?",
            [$providerId]
        );
        while ($row = sqlFetchArray($res)) {
            $ids[] = (int)$row['pc_catid'];
        }
        return $ids;
    }

    /**
     * True if the provider serves the clinic (or no grants exist = unrestricted fallback off).
     */
    public function providerServesClinic(int $providerId, int $pcCatid): bool
    {
        return in_array($pcCatid, $this->getProviderClinics($providerId), true);
    }
}
