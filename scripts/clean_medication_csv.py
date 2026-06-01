#!/usr/bin/env python3
"""
Phase 9.0 — medication_list.csv → medication_list.cleaned.csv

Reads the messy seed CSV and produces:
  - medication_list.cleaned.csv  (canonical input for the pharmacy import)
  - medication_cleanup_audit.txt (one line per dropped/flagged row, with reason)

Cleanup rules (see CLAUDE_CODE_INSTRUCTIONS.md §9.0.1):
  1. Drop the trailing HTML/garbage row that came from a web-form export.
  2. Drop the leading blank index column (col 0 in source).
  3. Split Category on ' -- ' into category + form, when the right-hand side
     matches a known dosage form. Otherwise leave form blank and keep the
     whole Category as-is.
  4. Parse 'TSh 1,234.50' (or empty) into numeric. Empty → None → blank.
  5. Rows with blank Generic Name are kept but `import=false` so the pharmacy
     loader skips them; they go to the audit file for the clinic admin to
     review and re-classify.

Output schema:
    brand, generic, unit_purchase_tzs, selling_tzs, category, form, import, notes
"""
import csv
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent.parent
SOURCE = HERE / "medication_list.csv"
CLEAN  = HERE / "medication_list.cleaned.csv"
AUDIT  = HERE / "medication_cleanup_audit.txt"

KNOWN_FORMS = {
    "tablet", "capsule", "syrup", "suspension", "injection",
    "cream", "gel", "drops", "ointment", "spray", "iv",
    "suppository", "pessary", "lotion", "solution", "powder",
    "inhaler", "patch",
}

PRICE_RE = re.compile(r"TSh\s*([\d,]+\.?\d*)", re.IGNORECASE)


def parse_price(raw: str):
    if not raw:
        return ""
    m = PRICE_RE.search(raw)
    if not m:
        return ""
    return m.group(1).replace(",", "")


def split_category(raw: str):
    if " -- " not in raw:
        return raw.strip(), ""
    left, right = raw.split(" -- ", 1)
    category = left.strip()
    form_text = right.strip()
    form = form_text if form_text.lower() in KNOWN_FORMS else ""
    if not form and form_text.lower() != "unspecified":
        category = raw.strip()
    return category, form


def is_garbage_row(row):
    joined = ",".join(row).lower()
    markers = ("add to location", "remove from location", "<input", "<form", "</")
    return any(m in joined for m in markers)


def main():
    if not SOURCE.exists():
        print(f"ERROR: {SOURCE} not found", file=sys.stderr)
        return 2

    audit_lines = []
    out_rows = []

    with SOURCE.open(newline="", encoding="utf-8") as f:
        reader = csv.reader(f)
        header = next(reader)
        if len(header) < 6:
            print(f"ERROR: header has {len(header)} cols, expected 6+", file=sys.stderr)
            return 2

        for line_no, row in enumerate(reader, start=2):
            if not any(c.strip() for c in row):
                audit_lines.append(f"line {line_no}: dropped (empty row)")
                continue
            if is_garbage_row(row):
                audit_lines.append(f"line {line_no}: dropped (HTML/garbage marker)")
                continue
            while len(row) < 6:
                row.append("")
            brand   = row[1].strip()
            generic = row[2].strip()
            unit_pp = parse_price(row[3])
            selling = parse_price(row[4])
            category, form = split_category(row[5])

            notes = []
            import_flag = "true"
            if not generic:
                import_flag = "false"
                notes.append("blank-generic")
                audit_lines.append(
                    f"line {line_no}: flagged blank generic — brand='{brand}', category='{row[5].strip()}'"
                )
            if not brand and not generic:
                import_flag = "false"
                notes.append("blank-brand-and-generic")

            out_rows.append([
                brand, generic, unit_pp, selling, category, form, import_flag, ";".join(notes)
            ])

    with CLEAN.open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["brand","generic","unit_purchase_tzs","selling_tzs","category","form","import","notes"])
        w.writerows(out_rows)

    with AUDIT.open("w", encoding="utf-8") as f:
        f.write(f"medication_list cleanup audit\n")
        f.write(f"source: {SOURCE.name}  ->  cleaned: {CLEAN.name}\n")
        f.write(f"data rows out: {len(out_rows)}\n")
        f.write(f"audit items: {len(audit_lines)}\n")
        f.write("=" * 64 + "\n")
        f.write("\n".join(audit_lines))
        f.write("\n")

    print(f"Wrote {CLEAN.name}: {len(out_rows)} rows")
    print(f"Wrote {AUDIT.name}: {len(audit_lines)} audit items")
    importable = sum(1 for r in out_rows if r[6] == "true")
    print(f"  importable (import=true): {importable}")
    print(f"  flagged   (import=false): {len(out_rows) - importable}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
