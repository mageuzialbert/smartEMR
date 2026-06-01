<?php

/**
 * OPD Workflow Module — module information.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

return [
    'name' => 'OPD Workflow Module',
    'description' => 'Tanzanian OPD: clinic routing (appointment categories), payment-gated triage/doctor queues, reception registration + consultation billing.',
    'version' => '1.0.0',
    'author' => 'iPAB',
    'license' => 'GPL-3.0',
    'acl_category' => 'admin',
    'acl_section' => 'super',

    'require' => [
        'openemr' => '>=7.0.0',
    ],

    'tables' => [
        'opd_clinic_config',
        'opd_provider_clinic',
    ],

    'install' => [
        'sql' => 'sql/install.sql',
    ],

    'uninstall' => [
        'sql' => 'sql/uninstall.sql',
    ],
];
