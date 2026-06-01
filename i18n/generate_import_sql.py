#!/usr/bin/env python3
"""Generate idempotent SQL to load the Swahili OPD subset into OpenEMR.

Reads i18n/swahili_opd.csv (constant,definition) and writes /tmp/import_sw.sql:
  - ensures a 'sw' / 'Swahili' row in lang_languages (no duplicate on re-run)
  - for each constant that exists in lang_constants, upserts its Swahili
    definition for the Swahili lang_id (delete-then-insert => re-runnable)

Mirrors interface/language/csv/translation_utilities.php::verify_translation
(constant must already exist; we do not create new constants).
"""
import csv
import os

HERE = os.path.dirname(os.path.abspath(__file__))
CSV_PATH = os.path.join(HERE, "swahili_opd.csv")
OUT = "/tmp/import_sw.sql"


def q(s: str) -> str:
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


def main():
    rows = []
    with open(CSV_PATH, encoding="utf-8") as fh:
        r = csv.reader(fh)
        header = next(r, None)
        for row in r:
            if len(row) >= 2 and row[0].strip() and row[1].strip():
                rows.append((row[0], row[1]))

    lines = [
        "SET NAMES utf8mb4;",
        "START TRANSACTION;",
        "INSERT INTO lang_languages (lang_code, lang_description, lang_is_rtl)",
        "  SELECT 'sw', 'Swahili', 0 FROM DUAL",
        "  WHERE NOT EXISTS (SELECT 1 FROM lang_languages WHERE lang_code='sw');",
        "SET @sw := (SELECT lang_id FROM lang_languages WHERE lang_code='sw' LIMIT 1);",
    ]
    for const, defn in rows:
        c = q(const)
        d = q(defn)
        lines.append(
            "DELETE ld FROM lang_definitions ld "
            "JOIN lang_constants lc ON ld.cons_id=lc.cons_id "
            f"WHERE ld.lang_id=@sw AND lc.constant_name={c};"
        )
        lines.append(
            "INSERT INTO lang_definitions (cons_id, lang_id, definition) "
            f"SELECT cons_id, @sw, {d} FROM lang_constants "
            f"WHERE constant_name={c} LIMIT 1;"
        )
    lines.append("COMMIT;")
    lines.append(
        "SELECT lang_id, lang_code, lang_description FROM lang_languages "
        "WHERE lang_code='sw';"
    )
    lines.append(
        "SELECT CONCAT('sw_definitions=', COUNT(*)) FROM lang_definitions "
        "WHERE lang_id=@sw;"
    )

    with open(OUT, "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines) + "\n")
    print(f"rows={len(rows)} sql={OUT}")


if __name__ == "__main__":
    main()
