<?php

/**
 * OPD Workflow Module — bootstrap entry point.
 *
 * Auto-loaded by OpenEMR\Core\ModulesApplication for every registered+active
 * custom module (modules.mod_active=1 AND type=0). Registers the PSR-4 namespace
 * and wires the module's event subscribers.
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\OpdWorkflow\Bootstrap;

$file = OEGlobalsBag::getInstance()->get('fileroot');
$classLoader = new ModulesClassLoader($file);
$classLoader->registerNamespaceIfNotExists(
    'OpenEMR\\Modules\\OpdWorkflow\\',
    __DIR__ . DIRECTORY_SEPARATOR . 'src'
);

$eventDispatcher = OEGlobalsBag::getInstance()->get('kernel')->getEventDispatcher();
$bootstrap = new Bootstrap($eventDispatcher);
$bootstrap->subscribeToEvents();
