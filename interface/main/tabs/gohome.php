<?php

/**
 * smartEMR — nav-logo "Home".
 *
 * Clicking the top-bar logo should return the user to the default landing view
 * (the same place they reach right after login), not an external website.
 * The tabbed app (main.php) requires a fresh single-use token_main, so we issue
 * one here and redirect, mirroring interface/main/main_screen.php.
 *
 * Auth is enforced by globals.php (an unauthenticated hit redirects to login).
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Utils\RandomGenUtils;

$_SESSION['token_main_php'] = RandomGenUtils::createUniqueToken();
header('Location: main.php?token_main=' . urlencode($_SESSION['token_main_php']));
exit;
