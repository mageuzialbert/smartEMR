#!/usr/bin/env python3
"""
Phase 9 — read medication_list.cleaned.csv and emit:
  scripts/seed-drugs.sql

The SQL it produces:
  1. Adds missing drug_form list_options (syrup, gel, injection, suppository, pessary, spray, lotion)
  2. Turns on inhouse_pharmacy
  3. INSERTs one drug template per importable row
  4. Leaves inventory empty — clinic admin enters lot/expiry/qty via Inventory → Drugs → Transactions

Idempotent: REPLACE INTO for list_options, and DELETE-then-INSERT for drugs keyed
by name to keep re-runs clean. (We do not preserve drug_id across re-runs.)
"""
import csv
import re
from pathlib import Path

HERE = Path(__file__).resolve().parent.parent
CLEAN = HERE / "medication_list.cleaned.csv"
OUT = HERE / "scripts" / "seed-drugs.sql"

# Map cleaned-CSV form names → drug_form list_options.option_id.
# IDs 1-12 already exist. We add 13-19 below.
FORM_MAP = {
    "Tablet":      "2",
    "Capsule":     "3",
    "Suspension":  "1",
    "Solution":    "4",
    "Cream":       "10",
    "Ointment":    "11",
    "Drops":       "9",
    "Syrup":       "13",
    "Gel":         "14",
    "Injection":   "15",
    "Suppository": "16",
    "Pessary":     "17",
    "Spray":       "18",
    "Lotion":      "19",
}
NEW_FORMS = [
    ("13", "syrup",       100),
    ("14", "gel",         110),
    ("15", "injection",   120),
    ("16", "suppository", 130),
    ("17", "pessary",     140),
    ("18", "spray",       150),
    ("19", "lotion",      160),
]

SIZE_RE = re.compile(r"(\d+\.?\d*)\s*(mg|mcg|g|mL|ml|IU|%)", re.IGNORECASE)

# Order matters: longer / more specific tokens first.
NAME_FORM_HINTS = [
    (re.compile(r"\b(suppositor(?:y|ies)|supp)\b",   re.I), "Suppository"),
    (re.compile(r"\b(suspension|susp\.?)\b",          re.I), "Suspension"),
    (re.compile(r"\b(pessar(?:y|ies))\b",             re.I), "Pessary"),
    (re.compile(r"\b(ointment|oint)\b",               re.I), "Ointment"),
    (re.compile(r"\b(capsules?|caps?)\b",             re.I), "Capsule"),
    (re.compile(r"\b(injection|inj\.?)\b",            re.I), "Injection"),
    (re.compile(r"\b(tablets?|tabs?)\b",              re.I), "Tablet"),
    (re.compile(r"\b(syrups?|syp\.?)\b",              re.I), "Syrup"),
    (re.compile(r"\b(lotion)\b",                      re.I), "Lotion"),
    (re.compile(r"\b(spray)\b",                       re.I), "Spray"),
    (re.compile(r"\b(cream)\b",                       re.I), "Cream"),
    (re.compile(r"\b(drops?|gtt\.?)\b",               re.I), "Drops"),
    (re.compile(r"\b(gel)\b",                         re.I), "Gel"),
]


def infer_form_from_name(name: str) -> str:
    """Fallback form inference when the cleaned CSV's form column is empty."""
    for rx, label in NAME_FORM_HINTS:
        if rx.search(name):
            return label
    return ""


def parse_size_unit(name: str):
    """Try to extract '500mg' or '10mL' etc. from the drug name."""
    m = SIZE_RE.search(name)
    if not m:
        return "", "0"
    size = m.group(1)
    unit_raw = m.group(2).lower()
    unit_map = {"mg": "1", "mcg": "7", "g": "8", "ml": "9", "iu": "7", "%": "0"}
    return size, unit_map.get(unit_raw, "0")


def sql_escape(s: str) -> str:
    return s.replace("\\", "\\\\").replace("'", "''")


def main():
    rows = []
    with CLEAN.open(newline="", encoding="utf-8") as f:
        for row in csv.DictReader(f):
            if row["import"] != "true":
                continue
            rows.append(row)

    out_lines = [
        "-- Phase 9 — pharmacy templates seed",
        f"-- Generated from {CLEAN.name}, {len(rows)} importable rows.",
        "-- Idempotent: list_options use REPLACE INTO; drugs are wiped then re-inserted.",
        "",
        "-- 1. Add missing drug forms to the list",
    ]
    for opt_id, title, seq in NEW_FORMS:
        out_lines.append(
            f"REPLACE INTO list_options (list_id, option_id, title, seq, is_default, option_value, activity) "
            f"VALUES ('drug_form', '{opt_id}', '{title}', {seq}, 0, 0, 1);"
        )

    out_lines += [
        "",
        "-- 2. Enable in-house pharmacy dispensing",
        "REPLACE INTO globals (gl_name, gl_index, gl_value) VALUES ('inhouse_pharmacy', 0, '1');",
        "",
        "-- 3. Clear and reseed drug templates",
        "DELETE FROM drugs;",
        "ALTER TABLE drugs AUTO_INCREMENT = 1;",
        "",
        "-- Bulk insert",
    ]

    unmapped = set()
    cols = "name, form, size, unit, active, consumable, dispensable, allow_multiple"

    for r in rows:
        name = r["generic"] or r["brand"]
        if not name:
            continue
        form_csv = r["form"].strip()
        if not form_csv:
            form_csv = infer_form_from_name(name) or infer_form_from_name(r["brand"])
        form_id = FORM_MAP.get(form_csv, "0")
        if form_csv and form_csv not in FORM_MAP:
            unmapped.add(form_csv)
        size, unit_id = parse_size_unit(name)
        is_consumable = 1 if r["category"].lower().startswith("consumable") else 0
        out_lines.append(
            f"INSERT INTO drugs ({cols}) VALUES "
            f"('{sql_escape(name)}', '{form_id}', '{sql_escape(size)}', '{unit_id}', 1, {is_consumable}, 1, 1);"
        )

    out_lines += [
        "",
        "-- 4. Verify",
        "SELECT COUNT(*) AS templates_loaded FROM drugs;",
        "SELECT form, COUNT(*) AS n FROM drugs GROUP BY form ORDER BY n DESC LIMIT 10;",
    ]

    OUT.write_text("\n".join(out_lines) + "\n", encoding="utf-8")
    print(f"Wrote {OUT.relative_to(HERE)} — {len(rows)} drug INSERTs")
    if unmapped:
        print(f"Unmapped forms (left as '0' / blank): {sorted(unmapped)}")


if __name__ == "__main__":
    main()
