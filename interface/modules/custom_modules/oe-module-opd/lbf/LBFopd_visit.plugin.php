<?php

/**
 * OPD Visit Note (LBFopd_visit) — form UI enhancements.
 *
 * Auto-loaded by interface/forms/LBF/new.php for this form only (it includes
 * $OE_SITE_DIR/LBF/<formname>.plugin.php). Provides:
 *   1. Chief Complaints -> a Select2 tag picker: blank, autocompletes from the
 *      curated 'opd_complaint' list as the doctor types, allows free-text
 *      complaints, and shows selections as removable chips.
 *   2. Sections -> the native checkbox-toggled groups become click-to-collapse
 *      accordion headers with a chevron.
 *
 * Authoritative copy lives in the module at oe-module-opd/lbf/; deployed into the
 * sites volume by scripts/install-lbf-plugins.sh (re-run after a sites reset).
 *
 * @package OpenEMR\Modules\OpdWorkflow
 * @license GPL-3.0
 */

/**
 * Emitted into the <head> <script> of new.php (runs before DOM ready).
 * Suppresses the harmless "Modal Template Fetch:" alert raised by the portal
 * signer (portal/sign/assets/signer_api.js), which new.php loads unconditionally
 * for every LBF form via signer_head(): its modal-template fetch returns HTML and
 * the script parses it as JSON. The OPD Visit Note has no signature field, so the
 * signer is unused here — we just silence its startup alert. All other alerts pass
 * through unchanged.
 */
function LBFopd_visit_javascript(): void
{
    echo <<<JS
(function () {
    var _alert = window.alert;
    window.alert = function (msg) {
        if (typeof msg === 'string' && msg.indexOf('Modal Template Fetch:') === 0) {
            return;
        }
        return _alert.apply(window, arguments);
    };
})();
JS;
}

/**
 * Emitted inside the on-load <script> at the end of new.php (DOM is built; jQuery,
 * Bootstrap and Select2 are available).
 */
function LBFopd_visit_javascript_onload(): void
{
    $placeholder = xlj('Type a complaint…');
    $me = (int)($_SESSION['authUserID'] ?? 0);
    $meJs = $me > 0 ? (string)$me : '';
    echo <<<JS
$(function () {
    // ---- Provider: auto-select the logged-in doctor and lock it ----
    var \$prov = $('select[name="form_provider_id"]');
    if (\$prov.length) {
        var me = '$meJs';
        if (!\$prov.val() && me) {           // new form: default to the logged-in provider
            \$prov.val(me);
        }
        var finalVal = \$prov.val();
        if (finalVal) {                       // lock it (a disabled select is not submitted,
            \$prov.prop('disabled', true)     //  so mirror the value in a hidden input)
                  .attr('title', 'Locked to the attending doctor');
            \$prov.after($('<input type="hidden" name="form_provider_id">').val(finalVal));
        }
    }

    // ---- Chief Complaints: autocomplete tag picker ----
    var \$cc = $('select[name^="form_chief_complaints"]');
    if (\$cc.length) {
        \$cc.find("option[value='']").remove();           // drop the blank "Unassigned" option
        if (\$cc.hasClass('select2-hidden-accessible')) {
            \$cc.select2('destroy');
        }
        \$cc.select2({
            theme: 'bootstrap4',
            width: '100%',
            tags: true,                                    // allow free-typed complaints
            multiple: true,
            placeholder: $placeholder,
            tokenSeparators: [',']
        });
    }

    // ---- Sections: checkbox groups -> accordions ----
    if (!document.getElementById('opd-acc-style')) {
        $('<style id="opd-acc-style">' +
          '.opd-acc-header{background:#eef2f7;border:1px solid #cdd7e2;border-radius:.25rem;' +
          'padding:.4rem .6rem;margin:.6rem 0 .25rem;cursor:pointer;display:flex;align-items:center;}' +
          '.opd-acc-header:hover{background:#e2e8f0;}' +
          '.opd-acc-header .opd-acc-chevron{width:1rem;text-align:center;margin-right:.5rem;}' +
          '.opd-acc-header strong{font-size:1rem;}' +
          '</style>').appendTo('head');
    }

    $('input[name^="form_cb_lbf"]').each(function () {
        var \$cb = $(this);
        var divid = 'div_' + \$cb.attr('name').replace('form_cb_', '');
        var \$body = $('#' + divid);
        if (!\$body.length) {
            return;
        }
        var \$span = \$cb.closest('label').closest('span');
        var title = \$cb.closest('label').find('strong').text();
        var open = \$body.css('display') !== 'none';
        \$cb.remove();                                      // remove checkbox so divclick() no longer fires
        \$span.addClass('opd-acc-header').html(
            '<i class="fa fa-chevron-' + (open ? 'down' : 'right') + ' opd-acc-chevron"></i><strong></strong>'
        );
        \$span.find('strong').text(title);                 // set title as text (avoids HTML injection)
        \$span.on('click', function () {
            var vis = \$body.is(':visible');
            \$body.slideToggle(120);
            \$span.find('.opd-acc-chevron')
                 .toggleClass('fa-chevron-down', !vis)
                 .toggleClass('fa-chevron-right', vis);
        });
    });
});
JS;
}
