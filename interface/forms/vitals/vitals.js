/*
 * vitals_functions.js
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// ----- Tanzanian OPD: vitals reference ranges & automatic abnormal flagging -----
// Patient age (in months) is supplied by the form on init() and drives age-aware ranges.
let patientAgeMonths = null;

// Age-dependent normal ranges, [low, high]. Keyed by the vital's column/input name.
// These are pilot defaults the clinic can tune.
const VITAL_RANGES_BY_BAND = {
    infant:     { pulse: [100, 160], respiration: [30, 60], bps: [70, 100], bpd: [50, 65] }, // 0–11 mo
    toddler:    { pulse: [90, 150],  respiration: [24, 40], bps: [80, 110], bpd: [50, 80] }, // 12–35 mo
    preschool:  { pulse: [80, 120],  respiration: [22, 34], bps: [80, 110], bpd: [50, 80] }, // 3–5 y
    school:     { pulse: [70, 110],  respiration: [18, 30], bps: [85, 120], bpd: [55, 80] }, // 6–11 y
    adolescent: { pulse: [60, 100],  respiration: [12, 20], bps: [90, 120], bpd: [60, 80] }, // 12–17 y
    adult:      { pulse: [60, 100],  respiration: [12, 20], bps: [90, 120], bpd: [60, 80] }  // 18+
};

// Age-independent normal ranges. NOTE: temperature is evaluated in Fahrenheit (the stored/USA unit).
const VITAL_RANGES_CONST = {
    temperature: [97.0, 99.5],
    oxygen_saturation: [95, 100]
};

// BMI normal range — applied to adults only (pediatric BMI is percentile-based, out of scope).
const BMI_NORMAL_RANGE = [18.5, 24.9];

// Inputs that can be auto-flagged. Drives the page-load sweep and per-field listeners.
const VITAL_FLAG_INPUTS = ['bps', 'bpd', 'pulse', 'respiration', 'temperature', 'oxygen_saturation', 'BMI'];

// Convert an OpenEMR age value (a number of years, or a string like "8 month(s)") to total months.
function parseAgeToMonths(age) {
    if (age === null || age === undefined || age === "") {
        return null;
    }
    let s = String(age).toLowerCase();
    if (s.indexOf('month') >= 0) {
        let m = parseInt(s, 10);
        return isNaN(m) ? null : m;
    }
    let years = parseFloat(s);
    return isNaN(years) ? null : Math.round(years * 12);
}

function bandForMonths(m) {
    if (m === null) return 'adult'; // unknown age → adult ranges
    if (m < 12)  return 'infant';
    if (m < 36)  return 'toddler';
    if (m < 72)  return 'preschool';
    if (m < 144) return 'school';
    if (m < 216) return 'adolescent';
    return 'adult';
}

// Resolve the normal [low, high] range for a given vital input, given the patient's age band.
function rangeForVital(input) {
    if (input === 'BMI') {
        // adults only (>= 18 y)
        return (patientAgeMonths !== null && patientAgeMonths < 216) ? null : BMI_NORMAL_RANGE;
    }
    if (VITAL_RANGES_CONST[input]) {
        return VITAL_RANGES_CONST[input];
    }
    let band = VITAL_RANGES_BY_BAND[bandForMonths(patientAgeMonths)] || {};
    return band[input] || null;
}

// Read the canonical numeric value for a vital. For conversion fields (weight/height/temperature)
// the canonical USA-unit value lives in the hidden "<input>_input" field; for plain textboxes and
// BMI that same id holds the visible value.
function evaluateVital(input) {
    // In metric-only / us-only display modes the conversion template renders the interpretation
    // dropdown in both the (hidden) US row and the (hidden) metric row with the same id, so pick
    // the one that is actually visible (not inside a row with class "hide").
    let candidates = document.querySelectorAll('[id="interpretation_' + input + '"]');
    let select = null;
    candidates.forEach(function(node) {
        let row = node.closest('tr');
        if (!select && (!row || !row.classList.contains('hide'))) {
            select = node;
        }
    });
    if (!select) {
        select = candidates[0] || null;
    }
    if (!select) {
        return; // no Abn selector for this row
    }
    let td = select.closest('td');
    let range = rangeForVital(input);

    let valNode = document.getElementById(input + '_input');
    let raw = valNode ? valNode.value : '';
    let val = parseFloat(raw);

    let clear = function () {
        select.classList.remove('vital-normal', 'vital-abnormal');
        if (td) {
            td.classList.remove('vital-normal', 'vital-abnormal');
        }
    };

    if (!range || raw === '' || isNaN(val)) {
        clear();
        return;
    }

    let code;
    if (val < range[0]) {
        code = 'L';
    } else if (val > range[1]) {
        code = 'H';
    } else {
        code = 'N';
    }
    select.value = code;

    let abnormal = (code !== 'N');
    select.classList.toggle('vital-abnormal', abnormal);
    select.classList.toggle('vital-normal', !abnormal);
    if (td) {
        td.classList.toggle('vital-abnormal', abnormal);
        td.classList.toggle('vital-normal', !abnormal);
    }
}

(function(window, oeUI) {

    let translations = {};
    let webroot = null;

    function vitalsFormSubmitted() {
        var invalid = "";

        var elementsToValidate = ['weight_input_usa', 'weight_input_metric', 'height_input_usa', 'height_input_metric', 'bps_input', 'bpd_input'];

        for (var i = 0; i < elementsToValidate.length; i++) {
            var current_elem_id = elementsToValidate[i];
            var tag_name = vitalsTranslations[current_elem_id] || "<unknown_tag_name>";

            document.getElementById(current_elem_id).classList.remove('error');

            if (isNaN(document.getElementById(current_elem_id).value)) {
                invalid += vitalsTranslations['invalidField'] + ":" + vitalsTranslations[current_elem_id] + "\n";
                document.getElementById(current_elem_id).className = document.getElementById(current_elem_id).className + " error";
                document.getElementById(current_elem_id).focus();
            }

            if (invalid.length > 0) {
                invalid += "\n" + vitalsTranslations['validateFailed'];
                alert(invalid);
                return false;
            } else {
                return top.restoreSession();
            }
        }
    }

    function convInputElement(evt) {
        let node = evt.currentTarget;
        if (!node) {
            console.error("Missing node from event");
            return;
        }
        let system = node.dataset.system || "usa";
        let unit = node.dataset.unit || "";
        let targetSaveUnit = node.dataset.targetInput || "";
        let targetInputConv = node.dataset.targetInputConv || "";
        let precision = vitalsGetPrecision(node, 2);

        // we need to convert the value and store the original value in the hidden input field that we end up saving

        // we then need to show a two digit representation of the value
        let value = node.value;
        let inputSave = document.getElementById(targetSaveUnit);
        if (!inputSave) {
            console.error("Failed to find node with data-target-input of ", targetSaveUnit);
            return;
        }
        let inputConv = document.getElementById(targetInputConv);
        if (!inputConv) {
            console.error("Failed to find node with data-target-input-conv of ", targetInputConv);
            return;
        }

        if (value != "") {
            let convValue = convUnit(system, unit, value);
            if (!isNaN(convValue)) {
                inputConv.value = convValue.toFixed(precision);
                // all values are saved in usa system units
                if (system !== "usa") {
                    inputSave.value = inputSave.value = convValue;
                } else {
                    inputSave.value = value;
                }
            } else {
                console.error("Failed to get valid number for input with id ", node.id, " with value ", value);
            }
        } else {
            inputSave.value = "";
            inputConv.value = "";
        }

        if (targetSaveUnit == "weight_input_usa" || targetSaveUnit == "height_input_usa") {
            calculateBMI();
        }

        // auto-flag the converted vital (e.g. "temperature_input" -> "temperature")
        evaluateVital(targetSaveUnit.replace(/_input$/, ''));
    }

    function initDOMEvents() {
        let vitalsForm = document.getElementById('vitalsForm');
        if (!vitalsForm) {
            console.error("Failed to find vitalsForm DOM Node");
            return;
        }
        document.getElementById('vitalsForm').addEventListener('submit', function(event) {
            if (!vitalsFormSubmitted()) {
                event.preventDefault(); // stop the form from submitting
                let firstErrorElement = document.querySelector('.error');
                if (firstErrorElement) {
                    firstErrorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return false;
            }
        });

        // we want to setup our reason code widgets
        if (oeUI.reasonCodeWidget) {
            oeUI.reasonCodeWidget.init(webroot);
        } else {
            console.error("Missing required dependency reason-code-widget");
            return;
        }

        let vitalsConvInputs = vitalsForm.querySelectorAll(".vitals-conv-unit");
        vitalsConvInputs.forEach(function(node) {
            node.addEventListener('change', convInputElement);
        });

        // Auto-flag plain numeric vitals as the user types, and run once on load for every vital
        // (including conversion fields and BMI) so already-saved records get colored too.
        VITAL_FLAG_INPUTS.forEach(function(input) {
            let node = document.getElementById(input + '_input');
            // conversion fields keep a hidden "<input>_input"; their visible inputs drive
            // convInputElement, so only attach a typing listener to genuine visible text inputs.
            if (node && node.tagName === 'INPUT' && node.type !== 'hidden') {
                node.addEventListener('input', function() { evaluateVital(input); });
            }
            evaluateVital(input);
        });
    }
    function init(webRootParam, vitalsTranslations, patientAge) {
        webroot = webRootParam;
        translations = vitalsTranslations;
        patientAgeMonths = parseAgeToMonths(patientAge);
        window.document.addEventListener("DOMContentLoaded", initDOMEvents);
    }

    let vitalsForm = {
        "init": init
    };
    window.vitalsForm = vitalsForm;
})(window, window.oeUI || {});

function vitalsGetPrecision(node, defaultValue) {
    defaultValue = defaultValue || 2;
    let precision = parseInt(node.dataset.precision || defaultValue);
    precision = !isNaN(precision) ? precision : defaultValue;
    return precision;
}

// TODO: we need to move all of these functions into the anonymous function and connect the events via event listeners
function convUnit(system, unit, value)
{
    if (unit == 'kg' || unit == 'lbs')
    {
        if (system == 'metric')
        {
            return convKgtoLb(value);
        }
        else
        {
            return convLbtoKg(value);
        }
    }

    if (unit == 'in' || unit == 'cm')
    {
        if (system == 'metric')
        {
            return convCmtoIn(value);
        }
        else
        {
            return convIntoCm(value);
        }
    }

    if (unit == 'C' || unit=='F')
    {
        if (system == 'metric')
        {
            return convCtoF(value);
        }
        else
        {
            return convFtoC(value);
        }
    }
}

function convLbtoKg(value) {
    var lb = value;
    var hash_loc=lb.indexOf("#");
    if(hash_loc>=0)
    {
        var pounds=lb.substr(0,hash_loc);
        var ounces=lb.substr(hash_loc+1);
        var num=parseInt(pounds)+parseInt(ounces)/16;
        lb=num;
        return lb;
    }
    if (lb == "0") {
        return 0;
    }
    else if (lb == parseFloat(lb)) {
        kg = lb*0.45359237;
        return kg;
    }
    else {
        return 0;
    }
}

function convKgtoLb(value) {
    var kg = value;

    if (kg == "0") {
        return 0;
    }
    else if (kg == parseFloat(kg)) {
        lb = kg/0.45359237;
        return lb;
    }
    else {
        return 0;
    }
}

function convIntoCm(value) {
    var inch = value;

    if (inch == "0") {
        return 0;
    }
    else if (inch == parseFloat(inch)) {
        cm = inch*2.54;
        return cm;
    }
    else {
        return 0;
    }
}

function convCmtoIn(value) {
    var cm = value

    if (cm == "0") {
        return 0;
    }
    else if (cm == parseFloat(cm)) {
        inch = cm/2.54;
        return inch;
    }
    else {
        return 0;
    }
}

function convFtoC(value) {
    var Fdeg = value;
    if (Fdeg == "0") {
        return 0;
    }
    else if (Fdeg == parseFloat(Fdeg)) {
        let Cdeg = (Fdeg-32)*5/9; // originally 0.5556 which is not precise!
        return Cdeg;
    }
    else {
        return 0;
    }
}

function convCtoF(value) {
    var Cdeg = value;
    if (Cdeg == "0") {
        return 0;
    }
    else if (Cdeg == parseFloat(Cdeg)) {
        Cdeg = parseFloat(Cdeg);
        let Fdeg = (Cdeg*9/5)+32; // originally 0.5556 which is not precise when working with 2 digit decimal conversions!
        return Fdeg;
    }
    else {
        $("#"+name).val("");
    }
}

function calculateBMI() {
    var bmi = 0;
    let bmiNode = document.getElementById("BMI_input");
    if (!bmiNode) {
        console.error("Failed to find node with id BMI_input");
        return;
    }

    let precision = vitalsGetPrecision(bmiNode, 2);

    let heightNode = document.getElementById("height_input_usa");
    if (!heightNode) {
        console.error("Failed to find node with id height_input_usa");
        return;
    }
    let weightNode = document.getElementById("weight_input_usa");
    if (!weightNode) {
        console.error("Failed to find node with id weight_input_usa");
        return;
    }
    var height = parseFloat(heightNode.value);
    var weight = parseFloat(weightNode.value);
    if(isNaN(height) || height == 0 || isNaN(weight) || weight == 0) {
        bmiNode.value = "";
    }
    else if((height == parseFloat(height)) && (weight == parseFloat(weight))) {
        bmi = weight/height/height*703;
        bmi = bmi.toFixed(precision);
        bmiNode.value = bmi;
    }
    else {
        bmiNode.value = "";
    }

    // re-run the BMI abnormal flag/color whenever BMI is recalculated
    evaluateVital('BMI');
}
