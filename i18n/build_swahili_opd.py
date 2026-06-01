#!/usr/bin/env python3
"""Build the Swahili OPD-subset translation CSV for the OpenEMR pilot.

Reads the validated constant list (constants that exist in lang_constants)
and emits i18n/swahili_opd.csv with columns: constant,definition

Any validated constant not covered by TRANSLATIONS is reported as UNCOVERED
so the dictionary can be completed. Constants in TRANSLATIONS that are not in
the validated list are ignored (kept for documentation/future use).
"""
import csv
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
MATCHED = os.environ.get("MATCHED", "/tmp/matched_u.txt")
OUT = os.path.join(HERE, "swahili_opd.csv")

# English constant -> Swahili (Kiswahili sanifu). Conservative, consistent
# clinical glossary. Ambiguous medical terms favour widely-understood usage;
# flag for staff review where noted in comments.
TRANSLATIONS = {
    # --- universal actions / UI chrome ---
    "Add": "Ongeza",
    "Add New": "Ongeza Mpya",
    "Update": "Sasisha",
    "Edit": "Hariri",
    "Delete": "Futa",
    "Save": "Hifadhi",
    "Save Changes": "Hifadhi Mabadiliko",
    "Cancel": "Ghairi",
    "Close": "Funga",
    "Back": "Rudi",
    "Next": "Endelea",
    "Previous": "Iliyopita",
    "Continue": "Endelea",
    "Submit": "Wasilisha",
    "Print": "Chapisha",
    "Search": "Tafuta",
    "Find": "Tafuta",
    "Clear": "Futa",
    "Reset": "Weka upya",
    "Refresh": "Onyesha upya",
    "Select": "Chagua",
    "View": "Tazama",
    "Show": "Onyesha",
    "Details": "Maelezo",
    "Yes": "Ndiyo",
    "No": "Hapana",
    "OK": "Sawa",
    "Required": "Inahitajika",
    "Optional": "Si lazima",
    "None": "Hakuna",
    "All": "Vyote",
    "Help": "Msaada",
    "Home": "Mwanzo",
    "Settings": "Mipangilio",
    "Logout": "Toka",
    "Login": "Ingia",
    "Username": "Jina la mtumiaji",
    "Password": "Nenosiri",

    # --- top-level navigation / modules ---
    "Patient": "Mgonjwa",
    "Patients": "Wagonjwa",
    "Clients": "Wateja",
    "New Patient": "Mgonjwa Mpya",
    "Calendar": "Kalenda",
    "Schedule": "Ratiba",
    "Messages": "Ujumbe",
    "Documents": "Nyaraka",
    "Reports": "Ripoti",
    "Administration": "Utawala",
    "Account": "Akaunti",
    "Chart": "Chati",
    "Authorizations": "Idhini",
    "Care Coordination": "Uratibu wa Huduma",
    "Clinical Reminders": "Vikumbusho vya Kitabibu",
    "Batch Communication Tool": "Zana ya Mawasiliano ya Kundi",

    # --- demographics / registration ---
    "Name": "Jina",
    "First Name": "Jina la Kwanza",
    "Middle Name": "Jina la Kati",
    "Last Name": "Jina la Ukoo",
    "Title": "Cheo",
    "Date of Birth": "Tarehe ya Kuzaliwa",
    "Age": "Umri",
    "Sex": "Jinsia",
    "Male": "Mwanaume",
    "Female": "Mwanamke",
    "Unknown": "Haijulikani",
    "Address": "Anwani",
    "City": "Mji",
    "State": "Mkoa",
    "Postal Code": "Msimbo wa Posta",
    "Country": "Nchi",
    "Phone": "Simu",
    "Home Phone": "Simu ya Nyumbani",
    "Mobile Phone": "Simu ya Mkononi",
    "Email": "Barua pepe",
    "Marital Status": "Hali ya Ndoa",
    "Occupation": "Kazi",
    "Race": "Kabila",
    "Ethnicity": "Asili ya Kabila",
    "Language": "Lugha",
    "Religion": "Dini",
    "Guardian": "Mlezi",
    "Spouse": "Mwenzi wa Ndoa",
    "Referral": "Rufaa",

    # --- insurance ---
    "Insurance": "Bima",
    "Insurance Company": "Kampuni ya Bima",
    "Policy": "Sera",

    # --- appointments / encounters ---
    "Appointment": "Miadi",
    "Appointments": "Miadi",
    "Visit": "Hudhurio",
    "Visits": "Mahudhurio",
    "Encounter": "Mkutano",
    "Encounters": "Mikutano",
    "New Encounter": "Mkutano Mpya",
    "Reason": "Sababu",
    "Chief Complaint": "Lalamiko Kuu",
    "Provider": "Mtoa Huduma",
    "Facility": "Kituo",
    "Status": "Hali",
    "Active": "Inatumika",
    "Inactive": "Haitumiki",
    "Pending": "Inasubiri",
    "Completed": "Imekamilika",

    # --- clinical / encounter form ---
    "Vitals": "Vipimo vya Mwili",
    "Height": "Urefu",
    "Weight": "Uzito",
    "Temperature": "Joto la Mwili",
    "Pulse": "Mpwito wa Moyo",
    "Respiration": "Upumuaji",
    "Blood Pressure": "Shinikizo la Damu",
    "Diagnosis": "Utambuzi",
    "Assessment": "Tathmini",
    "Plan": "Mpango",
    "Treatment": "Matibabu",
    "Allergies": "Mzio",
    "Medications": "Dawa",
    "Medication": "Dawa",
    "Medical Problems": "Matatizo ya Kiafya",
    "Problem": "Tatizo",
    "Problems": "Matatizo",
    "History": "Historia",
    "Family History": "Historia ya Familia",
    "Examination": "Uchunguzi",
    "Review of Systems": "Mapitio ya Mifumo ya Mwili",
    "Immunization": "Chanjo",
    "Immunizations": "Chanjo",

    # --- billing / cashier ---
    "Fee Sheet": "Karatasi ya Ada",
    "Fees": "Ada",
    "Billing": "Malipo",
    "Charges": "Gharama",
    "Payment": "Malipo",
    "Payments": "Malipo",
    "Invoice": "Ankara",
    "Amount": "Kiasi",
    "Balance": "Salio",
    "Total": "Jumla",
    "Due": "Inadaiwa",
    "Cash": "Fedha Taslimu",
    "Check": "Hundi",
    "Price": "Bei",
    "Quantity": "Idadi",
    "Discount": "Punguzo",
    "Method": "Njia",
    "Checkout": "Kamilisha Malipo",

    # --- orders / lab ---
    "Order": "Agizo",
    "Orders": "Maagizo",
    "Procedure": "Kipimo",
    "Procedures": "Vipimo",
    "Test": "Kipimo",
    "Tests": "Vipimo",
    "Result": "Matokeo",
    "Results": "Matokeo",

    # --- pharmacy / dispensary ---
    "Prescription": "Agizo la Dawa",
    "Prescriptions": "Maagizo ya Dawa",
    "Drug": "Dawa",
    "Drugs": "Dawa",
    "Inventory": "Bohari",
    "Dosage": "Kipimo cha Dawa",
    "Directions": "Maelekezo",
    "Frequency": "Mara ngapi",

    # --- generic fields / misc ---
    "Date": "Tarehe",
    "Time": "Saa",
    "Today": "Leo",
    "From": "Kutoka",
    "To": "Hadi",
    "Type": "Aina",
    "Category": "Kundi",
    "Description": "Maelezo",
    "Comment": "Maoni",
    "Comments": "Maoni",
    "Note": "Dokezo",
    "Notes": "Madokezo",

    # --- compound navigation / menu / report labels (OPD-relevant) ---
    "Patient/Client": "Mgonjwa/Mteja",
    "Find": "Tafuta",
    "Add/Edit Issue": "Ongeza/Hariri Suala",
    "Allergy List": "Orodha ya Mzio",
    "Medical Record": "Kumbukumbu ya Matibabu",
    "Clinical Notes": "Madokezo ya Kitabibu",
    "Patient Notes": "Madokezo ya Mgonjwa",
    "Patient Reminders": "Vikumbusho vya Mgonjwa",
    "Patient Education": "Elimu kwa Mgonjwa",
    "Patient Reports": "Ripoti za Mgonjwa",
    "Patient Ledger": "Leja ya Mgonjwa",
    "Patient Statement": "Taarifa ya Mgonjwa",
    "Patient Balances": "Masalio ya Wagonjwa",
    "Patient Tracker": "Kifuatiliaji cha Wagonjwa",
    "Patient List Creation": "Uundaji wa Orodha ya Wagonjwa",
    "New Documents": "Nyaraka Mpya",
    "Visit Forms": "Fomu za Hudhurio",
    "Care Plan": "Mpango wa Huduma",
    "Appointment Status": "Hali ya Miadi",
    "Calendar Settings": "Mipangilio ya Kalenda",
    "Flow Board": "Ubao wa Mtiririko",
    "Take Action": "Chukua Hatua",
    "Unassigned": "Haijapangwa",
    "Authorized": "Imeidhinishwa",
    "Messaging": "Utumaji Ujumbe",
    "Notification": "Arifa",
    "Login/Logout": "Ingia/Toka",
    "Address Book": "Kitabu cha Anwani",
    "ASAP": "Haraka Iwezekanavyo",

    # --- orders / lab (compound) ---
    "Procedure Order": "Agizo la Kipimo",
    "Procedure Orders": "Maagizo ya Vipimo",
    "Procedure Results": "Matokeo ya Vipimo",
    "Procedure Order Status": "Hali ya Agizo la Kipimo",
    "Procedure Catalog": "Orodha ya Vipimo",
    "Lab Queue": "Foleni ya Maabara",
    "Pending Review": "Inasubiri Mapitio",

    # --- pharmacy / dispensary (compound) ---
    "Drug Inventory": "Bohari ya Dawa",
    "Manage Drug Inventory": "Simamia Bohari ya Dawa",
    "Drug Dispensing Log": "Kumbukumbu ya Utoaji Dawa",
    "Prescription Refill Requests": "Maombi ya Kujaza Dawa Tena",
    "Pharmacy Dispensary": "Duka la Dawa",
    "Immunization Registry": "Daftari la Chanjo",
    "Insurance Companies": "Kampuni za Bima",

    # --- billing / cashier (compound) ---
    "Charge Capture": "Kunasa Gharama",
    "Bill Manager": "Kidhibiti cha Bili",
    "Bill Patient": "Mtoze Mgonjwa",
    "Superbill": "Bili Kuu",
    "Statements": "Taarifa",
    "Collections": "Makusanyo",
    "Daily Finance Report": "Ripoti ya Fedha ya Kila Siku",
    "Provider Reports": "Ripoti za Watoa Huduma",
    "Practice Reports": "Ripoti za Kituo",
    "Practice Settings": "Mipangilio ya Kituo",
    "Receipts": "Risiti",

    # --- second pass: OPD-relevant constants found uncovered against this DB ---
    "Add Patient": "Ongeza Mgonjwa",
    "Find Patient": "Tafuta Mgonjwa",
    "Search/Add": "Tafuta/Ongeza",
    "New/Search": "Mpya/Tafuta",
    "Create Visit": "Anzisha Hudhurio",
    "Visit History": "Historia ya Mahudhurio",
    "Visit Forms": "Fomu za Hudhurio",
    "Demographics": "Taarifa Binafsi",
    "Allergy": "Mzio",
    "Find": "Tafuta",
    "Lab": "Maabara",
    "Lab Results": "Matokeo ya Maabara",
    "Lab Documents": "Nyaraka za Maabara",
    "Lab Overview": "Muhtasari wa Maabara",
    "Order Status": "Hali ya Agizo",
    "Specimen": "Sampuli",
    "Collected": "Imekusanywa",
    "Patient Results": "Matokeo ya Mgonjwa",
    "Pharmacy": "Duka la Dawa",
    "Dose": "Kipimo",
    "Route": "Njia ya Kutumia",
    "Refills": "Mara za Kujaza Tena",
    "Dispensed": "Imetolewa",
    "Rx": "Dawa",
    "Charge": "Gharama",
    "Receipt": "Risiti",
    "Pay": "Lipa",
    "Paid": "Imelipwa",
    "Payment Method": "Njia ya Malipo",
    "Co-Pay": "Malipo ya Pamoja",
    "Copay": "Malipo ya Pamoja",
    "Credit Card": "Kadi ya Benki",
    "Billing Manager": "Kidhibiti cha Malipo",
    "Superbill": "Bili Kuu",
    "Superbill/Fee Sheet": "Bili Kuu/Karatasi ya Ada",
    "Providers": "Watoa Huduma",
    "Records": "Kumbukumbu",
    "Report": "Ripoti",
    "Referrals": "Rufaa",
    "Relationship": "Uhusiano",
    "Subscriber": "Mwanachama wa Bima",
    "Policy Number": "Namba ya Sera",
    "Group Number": "Namba ya Kundi",
    "Emergency Contact": "Mawasiliano ya Dharura",
    "Child": "Mtoto",
    "Self": "Mwenyewe",
    "Other": "Nyingine",
    "BMI": "BMI (Faharasa ya Uzito-Urefu)",
    "Allergy List": "Orodha ya Mzio",
    "Office Notes": "Madokezo ya Ofisi",
    "Issues": "Masuala",
    "Problem": "Tatizo",
    "New": "Mpya",
    "List": "Orodha",
    "Hide": "Ficha",
    "Loading": "Inapakia",
    "Please wait": "Tafadhali subiri",
    "Pending Review": "Inasubiri Mapitio",
    "New Documents": "Nyaraka Mpya",
    "Patient Reminders": "Vikumbusho vya Mgonjwa",
    "Patient Education": "Elimu kwa Mgonjwa",
    "Patient Ledger": "Leja ya Mgonjwa",
    "Log In": "Ingia",
    "Log Out": "Toka",
    "Preferences": "Mapendeleo",
    "Users": "Watumiaji",
    "Calendar": "Kalenda",
    "Clinical": "Kitabibu",
}


def main():
    if not os.path.exists(MATCHED):
        sys.exit(f"missing {MATCHED}")
    with open(MATCHED, encoding="utf-8") as fh:
        validated = [l.rstrip("\n") for l in fh if l.strip()]
    seen = set()
    validated = [c for c in validated if not (c in seen or seen.add(c))]

    covered, uncovered = [], []
    for c in validated:
        if c in TRANSLATIONS:
            covered.append(c)
        else:
            uncovered.append(c)

    os.makedirs(HERE, exist_ok=True)
    with open(OUT, "w", encoding="utf-8", newline="") as fh:
        w = csv.writer(fh)
        w.writerow(["constant", "definition"])  # header (skipped on import)
        for c in covered:
            w.writerow([c, TRANSLATIONS[c]])

    with open("/tmp/uncovered.txt", "w", encoding="utf-8") as fh:
        fh.write("\n".join(uncovered) + ("\n" if uncovered else ""))
    with open("/tmp/coverage.txt", "w", encoding="utf-8") as fh:
        fh.write(f"validated={len(validated)} covered={len(covered)} "
                 f"uncovered={len(uncovered)}\n")


if __name__ == "__main__":
    main()
