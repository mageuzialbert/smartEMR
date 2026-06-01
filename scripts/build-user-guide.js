// Build smartEMR_User_Guide.docx — role-based OPD user guide (v2.0) with ANNOTATED screenshots.
// Each screenshot carries numbered orange badges (drawn by capture-guide-screenshots.py); this
// script pairs every figure with a numbered legend table whose numbers match the badges.
// Run: node scripts/build-user-guide.js   (from project root)
const fs = require("fs");
const path = require("path");
const {
  Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell, ImageRun,
  Header, Footer, AlignmentType, LevelFormat, ExternalHyperlink, TableOfContents,
  HeadingLevel, BorderStyle, WidthType, ShadingType, VerticalAlign, PageNumber, PageBreak,
} = require(path.join(__dirname, "guide-tools", "node_modules", "docx"));

const ROOT = path.resolve(__dirname, "..");
const SHOTS = path.join(ROOT, "docs", "guide-screenshots");

// ---------- palette ----------
const BLUE = "1E5AA8";      // iPAB blue
const DARK = "1B2433";
const GREY = "666666";
const LIGHT = "EAF1FA";
const ORANGE = "E8470A";    // matches the on-image annotation badges

// ---------- helpers ----------
const T = (text, opts = {}) => new TextRun({ text, font: "Arial", ...opts });

// Read a PNG's pixel dimensions from its IHDR chunk (so figures keep their true aspect ratio).
function pngSize(file) {
  const b = fs.readFileSync(file);
  return { w: b.readUInt32BE(16), h: b.readUInt32BE(20) };
}

// Embedded screenshot at the given display width (px), aspect-correct.
function shot(file, caption, width = 600) {
  const p = path.join(SHOTS, file);
  const data = fs.readFileSync(p);
  const { w, h } = pngSize(p);
  const height = Math.round(width * h / w);
  return [
    new Paragraph({
      alignment: AlignmentType.CENTER,
      spacing: { before: 120, after: 40 },
      border: { top: { style: BorderStyle.SINGLE, size: 4, color: "CCCCCC" } },
      children: [new ImageRun({
        type: "png", data,
        transformation: { width, height },
        altText: { title: caption, description: caption, name: file },
      })],
    }),
    new Paragraph({
      alignment: AlignmentType.CENTER,
      spacing: { after: 120 },
      children: [T(caption, { italics: true, size: 18, color: GREY })],
    }),
  ];
}

// Numbered-legend cell: an orange circle-like badge mirroring the on-image number.
function badgeCell(n) {
  return new TableCell({
    borders: { top: noB, bottom: noB, left: noB, right: noB },
    width: { size: 520, type: WidthType.DXA },
    verticalAlign: VerticalAlign.CENTER,
    margins: { top: 20, bottom: 20, left: 40, right: 40 },
    shading: { type: ShadingType.CLEAR, fill: ORANGE },
    children: [new Paragraph({
      alignment: AlignmentType.CENTER,
      children: [T(String(n), { bold: true, color: "FFFFFF", size: 20 })],
    })],
  });
}
const noB = { style: BorderStyle.NONE, size: 0, color: "FFFFFF" };

// A screenshot followed by its numbered legend (legend = [[n, "what it is / what to do"], ...]).
function shotL(file, caption, legend, width = 600) {
  const rows = legend.map(([n, txt]) => new TableRow({
    children: [
      badgeCell(n),
      new TableCell({
        borders: { top: noB, bottom: noB, left: noB, right: noB },
        width: { size: 8600, type: WidthType.DXA },
        verticalAlign: VerticalAlign.CENTER,
        margins: { top: 20, bottom: 20, left: 120, right: 60 },
        children: [new Paragraph({ children: Array.isArray(txt) ? txt : [T(txt, { size: 20 })] })],
      }),
    ],
  }));
  return [
    ...shot(file, caption, width),
    new Table({ columnWidths: [520, 8600], rows }),
    new Paragraph({ spacing: { after: 200 } }),
  ];
}

// numbered step list (unique ref => restarts at 1). `items` = array of TextRun[] or string
function steps(ref, items) {
  return items.map((it) => new Paragraph({
    numbering: { reference: ref, level: 0 },
    spacing: { after: 80 },
    children: Array.isArray(it) ? it : [T(it)],
  }));
}

const bullets = (items) => items.map((it) => new Paragraph({
  numbering: { reference: "bullet", level: 0 },
  spacing: { after: 40 },
  children: Array.isArray(it) ? it : [T(it)],
}));

const para = (runs, opts = {}) => new Paragraph({
  spacing: { after: 120 }, ...opts,
  children: Array.isArray(runs) ? runs : [T(runs)],
});

const h1 = (text) => new Paragraph({ heading: HeadingLevel.HEADING_1, children: [T(text)] });
const h2 = (text) => new Paragraph({ heading: HeadingLevel.HEADING_2, children: [T(text)] });

const swahili = (text) => new Paragraph({
  spacing: { after: 120 },
  shading: { type: ShadingType.CLEAR, fill: LIGHT },
  border: { left: { style: BorderStyle.SINGLE, size: 18, color: BLUE } },
  indent: { left: 160 },
  children: [T("Swahili: ", { bold: true, size: 20, color: BLUE }), T(text, { size: 20, italics: true })],
});

const success = (text) => new Paragraph({
  spacing: { before: 60, after: 200 },
  children: [T("✔ Done when: ", { bold: true, color: "2E7D32" }), T(text)],
});

const note = (label, text, color = "B26A00", fill = "FFF4E5") => new Paragraph({
  spacing: { before: 80, after: 160 },
  shading: { type: ShadingType.CLEAR, fill },
  border: { left: { style: BorderStyle.SINGLE, size: 18, color } },
  indent: { left: 160 },
  children: [T(label + " ", { bold: true, color }), T(text)],
});

const tip = (text) => note("Tip:", text);
const roleLine = (user, job) => para([T("Login: "), T(user, { bold: true, font: "Courier New" }), T("   •   "), T(job)]);

// ---------- table helpers ----------
const tb = { style: BorderStyle.SINGLE, size: 1, color: "BBBBBB" };
const cb = { top: tb, bottom: tb, left: tb, right: tb };
function cell(children, { w, head = false, fill } = {}) {
  return new TableCell({
    borders: cb,
    width: { size: w, type: WidthType.DXA },
    verticalAlign: VerticalAlign.CENTER,
    shading: { type: ShadingType.CLEAR, fill: fill || (head ? BLUE : "FFFFFF") },
    children: (Array.isArray(children) ? children : [children]).map((c) =>
      typeof c === "string"
        ? new Paragraph({ children: [T(c, { size: 20, bold: head, color: head ? "FFFFFF" : "000000" })] })
        : c),
  });
}
function table(widths, rows, { headerFill } = {}) {
  return new Table({
    columnWidths: widths,
    margins: { top: 60, bottom: 60, left: 120, right: 120 },
    rows: rows.map((r, i) => new TableRow({
      tableHeader: i === 0,
      children: r.map((c, j) => cell(c, { w: widths[j], head: i === 0, fill: i === 0 ? headerFill : undefined })),
    })),
  });
}

// ============================================================
// CONTENT
// ============================================================
const children = [];

// ---- Cover ----
children.push(
  new Paragraph({ spacing: { before: 2400 } }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 0 },
    children: [T("smartEMR", { bold: true, size: 96, color: BLUE })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 80 },
    children: [T("Clinic User Guide", { size: 40, color: DARK })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 600 },
    children: [T("Outpatient (OPD) Workflow — Step by Step for Every Role", { size: 26, color: GREY })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 60 },
    children: [T("Reception → Cashier → Nurse → Doctor → Lab → Radiology → Pharmacy → Discharge",
      { size: 20, bold: true, color: BLUE })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 1600, after: 40 },
    children: [T("Version 2.0", { size: 22 }), T("    •    ", { size: 22, color: GREY }), T("May 2026", { size: 22 })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 40 },
    children: [T("Tanzanian OPD Pilot", { size: 22, color: GREY })] }),
  new Paragraph({ alignment: AlignmentType.CENTER,
    children: [T("Maintained by ", { size: 22, color: GREY }),
      new ExternalHyperlink({ children: [new TextRun({ text: "iPAB", style: "Hyperlink", font: "Arial", size: 22 })], link: "https://www.ipab.co.tz/" })] }),
  new Paragraph({ children: [new PageBreak()] }),
);

// ---- TOC ----
children.push(
  new Paragraph({ heading: HeadingLevel.HEADING_1, children: [T("Contents")] }),
  new TableOfContents("Table of Contents", { hyperlink: true, headingStyleRange: "1-2" }),
  new Paragraph({ children: [new PageBreak()] }),
);

// ---- About ----
children.push(
  h1("About This Guide"),
  para("This guide shows each member of clinic staff how to use smartEMR for their part of the outpatient (OPD) visit. It is written for everyday users — no computer or medical-records experience is assumed. Find the section for your role and work through the numbered steps."),
  para([T("Every screen picture in this guide has small "), T("orange numbers", { bold: true, color: ORANGE }), T(" pointing at the exact button or box to use. Under each picture, a list explains what each number means. Match the number on the picture to the number in the list.")]),
  para([T("smartEMR runs in a web browser, only on the clinic’s own computers. It is "), T("not", { bold: true }), T(" on the internet. Each member of staff has their own username and password — ask the clinic administrator for yours, and never share it.")]),
  swahili("Mwongozo huu unaonyesha kila mfanyakazi jinsi ya kutumia smartEMR. Namba za rangi ya machungwa kwenye picha zinaonyesha kitufe sahihi cha kubonyeza."),
  h2("The OPD Patient Journey"),
  para("A patient moves through the clinic in a fixed order. Each station does its part, then passes the patient on by changing the visit Status. smartEMR enforces the order — for example, the nurse only sees a patient after the cashier has taken payment, and the lab can only enter a result after the test has been paid for."),
  table([1330, 1330, 1330, 1330, 1330, 1330], [
    ["1. Reception", "2. Cashier", "3. Nurse", "4. Doctor", "5–6. Lab / Radiology", "7–8. Pharmacy / Discharge"],
    ["Find patient, start the visit, bill it", "Collect payment, per item", "Record vital signs, send to doctor", "Examine, diagnose, order tests, prescribe", "Run tests / imaging, enter results", "Dispense medicine, close the visit"],
  ]),
  new Paragraph({ spacing: { after: 200 } }),
  h2("Visit Status Codes"),
  para([T("Each visit carries a "), T("Status", { bold: true }), T(" that shows where the patient is in the journey. You will see these codes on the worklists and on the "), T("Patient Flow Board", { bold: true }), T(". After finishing your step, the system advances the status automatically (or you set it) so the next station knows the patient is ready.")]),
  table([1100, 3200, 5060], [
    ["Code", "Status", "Meaning / set by"],
    ["RG", "Registered", "Reception started the visit; awaiting the cashier"],
    ["PD", "Paid", "Cashier took payment; awaiting the nurse (triage)"],
    ["TR", "Triaged", "Nurse recorded vitals; awaiting the doctor"],
    ["WD", "With Doctor", "Doctor has opened the visit"],
    ["WL", "Awaiting Lab", "Doctor ordered a lab test / imaging"],
    ["LB", "Lab Complete", "Result entered and signed"],
    ["WP", "Awaiting Pharmacy", "Medicine to be dispensed"],
    ["CM", "Completed", "Visit closed; patient leaves the active board"],
  ], { headerFill: DARK }),
  new Paragraph({ children: [new PageBreak()] }),
);

// ---- Getting Started ----
children.push(
  h1("Getting Started (All Staff)"),
  h2("1. Log in"),
  ...steps("gs", [
    [T("Open the web browser (Chrome) on a clinic computer.")],
    [T("Type the clinic address and press Enter. (Ask your administrator — for example "), T("http://localhost:8300", { font: "Courier New" }), T(".)")],
    [T("Enter your "), T("Username", { bold: true }), T(" and "), T("Password", { bold: true }), T(", then click "), T("Login", { bold: true }), T(".")],
  ]),
  swahili("Fungua kivinjari, andika anwani ya kliniki, weka jina la mtumiaji na nenosiri, kisha bonyeza “Login” (Ingia)."),
  ...shotL("01_login.png", "Figure 1 — The smartEMR login screen.", [
    [1, "Type your username (for example reception1, doctor1)."],
    [2, "Type your password (ask the administrator for yours)."],
    [3, "Click Login."],
  ]),
  note("Security:", "Your password is personal. Never share it. If you forget it, ask the administrator to reset it. Always log out (top-right, click your name → Logout) when you leave the computer."),
  h2("2. Find your way around"),
  para([T("After you log in, the main screen opens. You only ever see the menus for "), T("your", { italics: true }), T(" job — each role has its own short menu and its own first screen (its “worklist”). So a cashier sees the cashier worklist, a nurse sees the triage queue, and so on.")]),
  ...shot("02_main_dashboard.png", "Figure 2 — The main screen after logging in. The blue menu bar runs along the top; your role’s worklist is one click away."),
  new Paragraph({ children: [new PageBreak()] }),
);

// ---- Station 1: Reception ----
children.push(
  h1("Station 1 — Reception"),
  roleLine("reception1", "Find the patient, start today’s visit, and send them to the cashier."),
  swahili("Mapokezi: mtafute mgonjwa, anza huduma ya leo kwa kuchagua kliniki, kisha mpeleke kwa mhasibu."),
  para([T("From the menu choose "), T("Start OPD Visit", { bold: true }), T(". (A brand-new patient must first be registered on the New Patient form; then search for them here.)")]),
  h2("Find the patient"),
  ...shotL("04_reception_search.png", "Figure 3 — Reception: find the patient.", [
    [1, "Type the patient’s name or Registration No."],
    [2, "Click Search."],
    [3, "Click Select on the correct patient’s row."],
  ]),
  swahili("Jina = Name;  Namba ya usajili = Registration No.;  Chagua = Select."),
  h2("Start the visit"),
  ...shotL("05_reception_allocate.png", "Figure 4 — Reception: choose the clinic and start the visit.", [
    [1, "Choose the clinic the patient is attending (General OPD, Gynaecology, Paediatrics, RCH, Dental)."],
    [2, "Click Allocate to clinic & bill. smartEMR creates today’s visit, bills the consultation (and registration for a first-ever visit), and sends the patient to the cashier."],
  ]),
  tip("If the patient already has an active visit in that clinic, smartEMR tells you — do not start a second one. Just direct them to the right station."),
  success("the patient appears on the cashier’s worklist with status RG (Registered)."),
  new Paragraph({ children: [new PageBreak()] }),
);

// ---- Station 2: Cashier ----
children.push(
  h1("Station 2 — Cashier"),
  roleLine("cashier1", "Collect payment for each item on the visit."),
  swahili("Mhasibu: pokea malipo kwa kila huduma (kuonana na daktari, vipimo, dawa)."),
  para([T("Your "), T("Worklist", { bold: true }), T(" shows every patient with money still to collect today — the consultation first, and later any tests, imaging or medicines the doctor adds.")]),
  ...shotL("06_cashier_worklist.png", "Figure 5 — The cashier worklist.", [
    [1, "The visit status (Registered = not yet paid; Paid = done)."],
    [2, "Click Open / Collect to take payment for that patient."],
  ]),
  h2("Take payment"),
  para([T("smartEMR collects "), T("per item", { bold: true }), T(". Tick the lines the patient is paying for now, choose how they are paying, and collect. Paying for a lab/imaging line is what unlocks that department to enter the result.")]),
  ...shotL("07_cashier_collect.png", "Figure 6 — Collecting payment, line by line.", [
    [1, "Tick each line you are collecting now (all are ticked by default)."],
    [2, "Change the amount only for a part payment; otherwise leave the full balance."],
    [3, "Choose the payment method: Cash, M-Pesa, Tigo Pesa, Airtel Money, Halopesa, Bank Transfer, NHIF, Other Insurance."],
    [4, "Click Collect selected. A receipt is recorded."],
  ]),
  swahili("Taslimu = Cash;  Salio = Balance;  Njia ya malipo = Payment method;  Kusanya = Collect."),
  success("the consultation is paid and the patient moves to the nurse’s triage queue (status PD)."),
  new Paragraph({ children: [new PageBreak()] }),
);

// ---- Station 3: Nurse ----
children.push(
  h1("Station 3 — Nurse (Triage)"),
  roleLine("nurse1", "Record the patient’s vital signs and send them to the doctor."),
  swahili("Muuguzi: pima vipimo vya msingi (uzito, shinikizo la damu, mapigo ya moyo, joto) na mpeleke kwa daktari."),
  para([T("Your "), T("Triage Queue", { bold: true }), T(" lists only paid patients waiting for vitals.")]),
  ...shotL("08_triage_queue.png", "Figure 7 — The nurse’s triage queue.", [
    [1, "Click Record vitals to open the vitals form for that patient."],
    [2, "Choose the doctor who will see the patient."],
    [3, "Click Triaged → Doctor to send them on."],
  ]),
  h2("Record the vitals"),
  ...shotL("09_vitals_form.png", "Figure 8 — The vitals form (metric units).", [
    [1, "Weight in kilograms (kg)."],
    [2, "Blood pressure — systolic (the top number), then diastolic below it."],
    [3, "Pulse (beats per minute)."],
    [4, "Temperature in °C."],
    [5, "Click Save. Out-of-range values are highlighted automatically."],
  ]),
  swahili("Uzito = Weight;  Shinikizo la damu = Blood Pressure;  Mapigo ya moyo = Pulse;  Joto = Temperature;  Hifadhi = Save."),
  note("Note:", "The nurse cannot open the pharmacy or dispense medicines — that is the pharmacist’s job. This is normal."),
  success("vitals are saved and the patient is sent to the doctor (status TR)."),
  new Paragraph({ children: [new PageBreak()] }),
);

// ---- Station 4: Doctor ----
children.push(
  h1("Station 4 — Doctor"),
  roleLine("doctor1", "Examine the patient, write the note and diagnosis, order tests, and prescribe."),
  swahili("Daktari: mchunguze mgonjwa, andika utambuzi, agiza vipimo, na andika dawa."),
  para([T("Your queue shows the patients waiting in your clinic. Open a patient to start, and use the encounter menu to reach the OPD Visit Note, Orders and Prescriptions.")]),
  ...shotL("10_doctor_queue.png", "Figure 9 — The doctor’s clinic queue.", [
    [1, "Choose which of your clinics’ queues to view."],
    [2, "Click Open to start the consultation (sets the visit to “With Doctor”)."],
    [3, "Click Discharge to close a finished visit."],
  ]),

  h2("4a. Write the OPD Visit Note"),
  para([T("Open "), T("Clinical → OPD Visit Note", { bold: true }), T(". The note has five sections — Chief Complaint, History, Examination, Diagnosis & Investigations, and Plan. The provider name is locked to you.")]),
  ...shotL("11_opd_note.png", "Figure 10 — The OPD Visit Note.", [
    [1, "Chief Complaints — click and choose from the list, or type your own."],
    [2, "Type the history and examination findings in each box. Enter the diagnosis lower down, then Save."],
  ]),
  swahili("Malalamiko makuu = Chief Complaints;  Historia = History;  Uchunguzi = Examination;  Utambuzi = Diagnosis."),

  h2("4b. Order a lab test or X-ray"),
  para([T("Open "), T("Orders", { bold: true }), T(" and choose the department: "), T("Clinic Laboratory", { bold: true }), T(", "), T("Clinic Radiology", { bold: true }), T(" or "), T("Clinic Procedures", { bold: true }), T(". The same screen is used for each; the cost is added to the bill automatically when you send the order.")]),
  ...shotL("12_order_lab.png", "Figure 11 — Ordering a lab test (radiology and procedures work the same way).", [
    [1, "The department the order is sent to (here, Clinic Laboratory)."],
    [2, "Click to choose the test or procedure (e.g. Malaria Rapid Test, Complete Blood Count; Chest X-ray for radiology)."],
    [3, "Click Send request. The charge is added to the cashier’s bill automatically."],
  ]),
  note("Important:", "The lab or radiology room can only enter the result after the patient has paid for that order at the cashier. Send the patient to the cashier first."),

  h2("4c. Prescribe medicine"),
  para([T("Open "), T("Clinical → Prescriptions", { bold: true }), T(". Add one row per medicine; the quantity is worked out for you from the dose, frequency and number of days.")]),
  ...shotL("14_prescribe.png", "Figure 12 — The prescription grid.", [
    [1, "Type the drug name and pick it from the list."],
    [2, "Dose (for example 1 or 2)."],
    [3, "How often: OD (once), BD (twice), TDS (three times), QDS, NOCTE, PRN, STAT."],
    [4, "For how many days."],
    [5, "Quantity — calculated for you automatically."],
    [6, "Click + Add drug for another medicine."],
    [7, "Click Save prescriptions. They go to the pharmacy."],
  ]),
  swahili("Dawa = Drug;  Kipimo = Dose;  Mara ngapi = Frequency;  Siku = Days;  Kiasi = Quantity;  Hifadhi dawa = Save prescriptions."),
  success("the note and diagnosis are saved, tests are ordered, and prescriptions are waiting at the pharmacy."),
  new Paragraph({ children: [new PageBreak()] }),
);

// ---- Cashier round 2 (orders) ----
children.push(
  h2("Back at the Cashier — paying for tests"),
  para([T("After the doctor orders tests or imaging, the patient returns to the cashier. The new charges appear on the same "), T("Collect Payment", { bold: true }), T(" screen, line by line.")]),
  ...shotL("15_cashier_collect_orders.png", "Figure 13 — The cashier collecting the lab and radiology charges.", [
    [1, "Each ordered test / X-ray shows as its own line with its own balance."],
    [2, "Tick the lines, choose the method, and click Collect selected. Paying a line unlocks its department."],
  ]),
  new Paragraph({ children: [new PageBreak()] }),
);

// ---- Station 5: Lab ----
children.push(
  h1("Station 5 — Laboratory"),
  roleLine("lab1", "Run the ordered test and enter the result."),
  swahili("Maabara: fanya kipimo kilichoagizwa na uweke majibu."),
  para([T("Your "), T("Results Worklist", { bold: true }), T(" shows your department’s orders. You can only enter a result once the order is "), T("Paid", { bold: true }), T(".")]),
  ...shotL("16_lab_results.png", "Figure 14 — The laboratory results worklist.", [
    [1, "Billing status: a green Paid badge means you may proceed; “Awaiting payment” means the patient must pay first."],
    [2, "Click Enter results to record and sign the result. (Unpaid orders show a greyed-out “Awaiting cashier” button instead.)"],
  ]),
  swahili("Majibu ya kipimo = Test Result;  Amelipa = Paid;  Weka majibu = Enter results."),
  success("the result is entered and signed; the doctor can now see it on the patient’s visit (status LB)."),
  new Paragraph({ children: [new PageBreak()] }),
);

// ---- Station 6: Radiology ----
children.push(
  h1("Station 6 — Radiology / Imaging"),
  roleLine("radiology1", "Perform the imaging and enter the result."),
  swahili("Radiolojia (X-ray / Ultrasound): piga picha iliyoagizwa na uweke majibu."),
  para([T("Radiology uses the same "), T("Results Worklist", { bold: true }), T(" as the lab, but it shows only "), T("imaging", { bold: true }), T(" orders (Chest X-ray, Ultrasound, and so on). The same payment rule applies — enter the result only after the order is paid.")]),
  ...shotL("17_radiology_results.png", "Figure 15 — The radiology results worklist.", [
    [1, "Your department’s imaging orders for the day."],
    [2, "Click Enter results once the order shows Paid; otherwise it stays “Awaiting cashier”."],
  ]),
  success("the imaging result is entered and signed for the doctor to review."),
  new Paragraph({ children: [new PageBreak()] }),
);

// ---- Station 7: Pharmacy ----
children.push(
  h1("Station 7 — Pharmacy"),
  roleLine("pharm1", "Confirm and bill the medicine, then dispense it after payment."),
  swahili("Famasi: thibitisha dawa na uipeleke kwa malipo, kisha itoe baada ya mgonjwa kulipa."),
  para([T("Dispensing has three steps: "), T("Confirm & bill", { bold: true }), T(" the medicine (this creates the charge and reserves nothing yet), the patient "), T("pays at the cashier", { bold: true }), T(", then you "), T("Dispense", { bold: true }), T(" it — which reduces the stock count. A medicine can only be dispensed after it is paid for.")]),
  ...shotL("18_pharmacy_queue.png", "Figure 16 — The pharmacy worklist.", [
    [1, "Each medicine’s status (To confirm → Awaiting payment → Paid – ready → Dispensed)."],
    [2, "Set or confirm the fee for the medicine."],
    [3, "Click Confirm & bill to send the charge to the cashier."],
    [4, "Click Dispense once it is paid — this hands over the medicine and reduces stock."],
  ]),
  swahili("Idadi = Quantity;  Hisa = In stock;  Thibitisha = Confirm;  Toa dawa = Dispense."),
  h2("Managing stock"),
  para([T("Open "), T("Inventory", { bold: true }), T(" to see every medicine and how much is on hand, to add new medicines, and to record deliveries.")]),
  ...shotL("19_pharmacy_inventory.png", "Figure 17 — Inventory management.", [
    [1, "Add Drug — add a new medicine to the catalogue."],
    [2, "Tran — record a stock delivery (choose Purchase) so the count goes up."],
    [3, "Search the catalogue by name."],
  ]),
  note("Important:", "A medicine with zero stock cannot be dispensed. When a delivery arrives, record it with Tran → Purchase so the on-hand count is correct."),
  success("the medicine is handed to the patient and the stock count is reduced."),
  new Paragraph({ children: [new PageBreak()] }),
);

// ---- Station 8: Discharge / Flow board ----
children.push(
  h1("Station 8 — Discharge & the Patient Flow Board"),
  roleLine("doctor1", "Close the finished visit so the patient leaves the active board."),
  swahili("Kumaliza huduma: funga huduma iliyokamilika ili mgonjwa aondoke kwenye orodha."),
  para([T("When tests, medicines and notes are all done, the doctor clicks "), T("Discharge", { bold: true }), T(" on the clinic queue (see Figure 9). The "), T("Patient Flow Board", { bold: true }), T(" is the shared screen that shows where every patient is right now — useful for the whole clinic to see who is waiting and for what.")]),
  ...shotL("20_flow_board.png", "Figure 18 — The Patient Flow Board.", [
    [1, "Each patient’s current status (here, “Awaiting Lab”), with the time waiting."],
    [2, "Filter the board by status or date to focus on a group of patients."],
  ]),
  success("the visit is closed (status CM) and the patient no longer appears on the active board."),
  new Paragraph({ children: [new PageBreak()] }),
);

// ---- Quick reference ----
children.push(
  h1("Quick Reference"),
  para("One line per station. Keep this page handy."),
  table([1450, 1620, 4480, 1370], [
    ["Station", "Login", "What you do", "Result status"],
    ["Reception", "reception1", "Start OPD Visit → find patient → choose clinic → Allocate & bill", "RG"],
    ["Cashier", "cashier1", "Worklist → Open / Collect → tick lines → Collect selected", "PD"],
    ["Nurse", "nurse1", "Triage Queue → Record vitals → Triaged → Doctor", "TR"],
    ["Doctor", "doctor1", "Queue → Open → OPD Visit Note → Orders → Prescriptions", "WD / WL"],
    ["Lab", "lab1", "Results Worklist → Enter results (after Paid)", "LB"],
    ["Radiology", "radiology1", "Results Worklist → Enter results (after Paid)", "LB"],
    ["Pharmacy", "pharm1", "Worklist → Confirm & bill → (paid) → Dispense", "WP"],
    ["Discharge", "doctor1", "Clinic queue → Discharge", "CM"],
  ], { headerFill: DARK }),
  new Paragraph({ spacing: { after: 240 } }),

  h1("Troubleshooting & FAQ"),
  ...bullets([
    [T("“Invalid username or password.” ", { bold: true }), T("Check Caps Lock and re-type. If it still fails, ask the administrator to reset your password.")],
    [T("“Access denied” / a menu is missing. ", { bold: true }), T("Your role only shows the screens for your job — this is by design. Ask the administrator if you believe you need more.")],
    [T("The patient is not on my worklist. ", { bold: true }), T("Each station appears only after the previous one finishes. Check the patient’s status: an unpaid patient will not reach the nurse; an unpaid test will not reach the lab.")],
    [T("I cannot enter a lab/X-ray result. ", { bold: true }), T("The order must be paid first. Send the patient to the cashier to pay that line.")],
    [T("I cannot dispense a medicine. ", { bold: true }), T("Either it has not been paid for yet (Confirm & bill → cashier → Dispense), or it is out of stock (record a delivery with Tran → Purchase).")],
    [T("Diagnosis search finds nothing. ", { bold: true }), T("The ICD-10 codes may not be loaded yet — see the Administrator Setup section. You can still type the diagnosis as text.")],
    [T("Wrong patient open. ", { bold: true }), T("Always check the patient name at the top of the screen before entering anything.")],
  ]),
  new Paragraph({ children: [new PageBreak()] }),
);

// ---- Admin appendix ----
children.push(
  h1("Appendix — Administrator Setup"),
  para([T("These one-time tasks are for the clinic "), T("administrator", { bold: true }), T(" (login "), T("admin", { bold: true, font: "Courier New" }), T("), not day-to-day staff. Do them before go-live.")]),
  h2("1. Load diagnosis (ICD-10) codes"),
  para([T("So the doctor’s Diagnosis box can search for diseases: go to "), T("Administration → Coding → ICD-10", { bold: true }), T(", download the external code set, and import it. (Takes a few minutes.)")]),
  h2("2. Enter the real clinic name and details"),
  para([T("Go to "), T("Administration → Globals → Appearance / Practice", { bold: true }), T(" and the "), T("Facilities", { bold: true }), T(" screen to set the clinic’s real name, address and phone — these appear on receipts and printouts.")]),
  h2("3. Set medicine and service prices"),
  para([T("Consultation and lab prices are pre-loaded in Tanzanian Shillings (TSh). Review them, and set prices for radiology, procedures and any medicines you sell, under "), T("Administration → Prices / Layouts", { bold: true }), T(" (or the pharmacist can set a medicine fee at Confirm & bill).")]),
  h2("4. Record opening stock"),
  para([T("Before dispensing, record what is physically on the shelves: "), T("Inventory → Tran → Purchase", { bold: true }), T(" for each medicine and lot, with the quantity and expiry date.")]),
  h2("5. Add staff and assign clinics"),
  para([T("Create a login for each member of staff under "), T("Administration → Users", { bold: true }), T(" and give them the right role (Front Office, Accounting, Clinicians, Physicians, Lab Technicians, Radiologists, Pharmacy). Doctors are granted the clinics whose queues they should see.")]),
  note("Backups:", "smartEMR backs up the database every night automatically. The administrator should keep copies off the computer and test a restore from time to time."),

  new Paragraph({ spacing: { before: 240 } }),
  new Paragraph({ alignment: AlignmentType.CENTER,
    children: [T("— End of guide —  ", { color: GREY }), T("smartEMR v2.0  •  Maintained by iPAB", { color: GREY })] }),
);

// ============================================================
// DOCUMENT
// ============================================================
const doc = new Document({
  creator: "smartEMR / iPAB",
  title: "smartEMR Clinic User Guide (v2.0)",
  description: "Role-based OPD user guide for smartEMR (OpenEMR), with annotated screenshots",
  styles: {
    default: { document: { run: { font: "Arial", size: 22 } } },
    paragraphStyles: [
      { id: "Title", name: "Title", basedOn: "Normal",
        run: { size: 72, bold: true, color: BLUE, font: "Arial" },
        paragraph: { spacing: { after: 120 }, alignment: AlignmentType.CENTER } },
      { id: "Heading1", name: "Heading 1", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 32, bold: true, color: BLUE, font: "Arial" },
        paragraph: { spacing: { before: 300, after: 160 }, outlineLevel: 0,
          border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: "C9D9EC" } } } },
      { id: "Heading2", name: "Heading 2", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 26, bold: true, color: DARK, font: "Arial" },
        paragraph: { spacing: { before: 220, after: 120 }, outlineLevel: 1 } },
    ],
  },
  numbering: {
    config: [
      { reference: "bullet", levels: [{ level: 0, format: LevelFormat.BULLET, text: "•", alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 520, hanging: 260 } } } }] },
      ..."gs".split(" ").map((ref) => ({
        reference: ref,
        levels: [{ level: 0, format: LevelFormat.DECIMAL, text: "%1.", alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 520, hanging: 260 } } } }],
      })),
    ],
  },
  sections: [{
    properties: { page: { margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 } } },
    headers: {
      default: new Header({ children: [new Paragraph({
        alignment: AlignmentType.RIGHT, spacing: { after: 0 },
        border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: "DDDDDD" } },
        children: [T("smartEMR — Clinic User Guide (v2.0)", { size: 16, color: GREY })],
      })] }),
    },
    footers: {
      default: new Footer({ children: [new Paragraph({
        alignment: AlignmentType.CENTER,
        border: { top: { style: BorderStyle.SINGLE, size: 4, color: "DDDDDD" } },
        children: [T("Page ", { size: 16, color: GREY }),
          new TextRun({ children: [PageNumber.CURRENT], font: "Arial", size: 16, color: GREY }),
          T(" of ", { size: 16, color: GREY }),
          new TextRun({ children: [PageNumber.TOTAL_PAGES], font: "Arial", size: 16, color: GREY }),
          T("    •    Clinic use only — contains patient information", { size: 16, color: GREY })],
      })] }),
    },
    children,
  }],
});

Packer.toBuffer(doc).then((buf) => {
  const out = path.join(ROOT, "smartEMR_User_Guide.docx");
  fs.writeFileSync(out, buf);
  console.log("Wrote", out, "(" + buf.length + " bytes)");
});
