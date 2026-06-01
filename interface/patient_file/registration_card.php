<?php

/**
 * interface/patient_file/registration_card.php
 *
 * Printable clinic registration card shown right after a patient is registered
 * (auto-opened from interface/new/new_comprehensive_save.php). Shows the clinic name,
 * patient name, Registration No. (pubpid) and DOB with a Code128 barcode of the
 * Registration No., which is searchable in the patient finder (External ID column).
 *
 * Reuses the bundled barcode renderer (library/classes/php-barcode.php, class Barcode).
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU GPL 3
 */

require_once("../globals.php");

use OpenEMR\Common\Twig\TwigContainer;

// Quiet the GD/library deprecation notices so they never leak into output.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

$card_pid = (int)($_GET['pid'] ?? 0);
if (!$card_pid) {
    $card_pid = (int)($pid ?? 0);
}

$pat = sqlQuery(
    "SELECT fname, mname, lname, pubpid, DOB FROM patient_data WHERE pid = ? LIMIT 1",
    [$card_pid]
);

if (empty($pat)) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()
        ->render('core/unauthorized.html.twig', ['pageTitle' => xl("Registration Card")]);
    exit;
}

// Clinic name from the default service-location facility (falls back to any facility).
$fac = sqlQuery("SELECT name FROM facility WHERE service_location = 1 ORDER BY id LIMIT 1")
    ?: sqlQuery("SELECT name FROM facility ORDER BY id LIMIT 1");
$clinicName = $fac['name'] ?? 'Clinic';

$code = (string)$pat['pubpid'];
$fullName = trim($pat['fname'] . ' ' . ($pat['mname'] ? $pat['mname'] . ' ' : '') . $pat['lname']);
$dob = oeFormatShortDate($pat['DOB']);

// ---- Build a Code128 barcode PNG of the Registration No. (data URI) ----
$barcodeImg = '';
if ($code !== '' && class_exists('Barcode') && function_exists('imagecreatetruecolor')) {
    $mw = 2;        // module width (px)
    $bh = 60;       // barcode height (px)
    $pad = 12;      // white margin (px)
    $canvasW = 1200;
    $canvasH = $bh + (2 * $pad);
    $img = imagecreatetruecolor($canvasW, $canvasH);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefilledrectangle($img, 0, 0, $canvasW, $canvasH, $white);
    // (x,y) is the barcode CENTRE in this library.
    $res = Barcode::gd($img, $black, intdiv($canvasW, 2), $pad + intdiv($bh, 2), 0, 'code128', ['code' => $code], $mw, $bh);
    $bw = (int)($res['width'] ?? 200);
    $cropW = min($bw + (2 * $pad), $canvasW);
    $cropX = max(0, intdiv($canvasW, 2) - intdiv($bw, 2) - $pad);
    $out = function_exists('imagecrop')
        ? imagecrop($img, ['x' => $cropX, 'y' => 0, 'width' => $cropW, 'height' => $canvasH])
        : false;
    ob_start();
    imagepng($out ?: $img);
    $png = ob_get_clean();
    if ($out) {
        imagedestroy($out);
    }
    imagedestroy($img);
    $barcodeImg = 'data:image/png;base64,' . base64_encode($png);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title><?php echo xlt('Registration Card'); ?></title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 16px; }
        .card {
            width: 320px; border: 2px solid #222; border-radius: 8px;
            padding: 14px 16px; margin: 0 auto;
        }
        .clinic { font-size: 15px; font-weight: bold; text-align: center;
            border-bottom: 1px solid #888; padding-bottom: 6px; margin-bottom: 8px; }
        .name { font-size: 18px; font-weight: bold; margin-bottom: 2px; }
        .row { font-size: 13px; margin: 2px 0; }
        .row .lbl { color: #555; }
        .regno { font-size: 16px; font-weight: bold; letter-spacing: 1px; }
        .barcode { text-align: center; margin-top: 10px; }
        .barcode img { max-width: 100%; height: 60px; }
        .actions { text-align: center; margin-top: 14px; }
        @media print { .actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body onload="window.print();">
    <div class="card">
        <div class="clinic"><?php echo text($clinicName); ?></div>
        <div class="name"><?php echo text($fullName); ?></div>
        <div class="row"><span class="lbl"><?php echo xlt('Registration No.'); ?>:</span>
            <span class="regno"><?php echo text($code); ?></span></div>
        <div class="row"><span class="lbl"><?php echo xlt('DOB'); ?>:</span> <?php echo text($dob); ?></div>
        <?php if ($barcodeImg) { ?>
            <div class="barcode"><img src="<?php echo attr($barcodeImg); ?>" alt="<?php echo attr($code); ?>" /></div>
        <?php } ?>
    </div>
    <div class="actions">
        <button type="button" onclick="window.print();"><?php echo xlt('Print'); ?></button>
        <button type="button" onclick="window.close();"><?php echo xlt('Close'); ?></button>
    </div>
</body>
</html>
