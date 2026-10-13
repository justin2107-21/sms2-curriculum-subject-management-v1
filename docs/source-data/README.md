# CMO No. 25, s. 2015 — BSIT source transcription

This package records a visual transcription of the user-provided scan
`C:\xampp\csms-reference-documents\CMO-no.-25-s.-2015.pdf` without copying
the PDF or page images into Git. The source fingerprint, page count, documentary
identity and limitations are in `ched-cmo25-2015-bsit.json`.

The supplied scan is 57 PDF pages. Page image indices are one-based PDF indices;
the `READ_ME_FIRST.txt` alongside the images explains this mapping. Printed
document page numbers are independently recorded for each source table.

Section 9.1 is transcribed as a component list, while Section 9.2 is stored as
separate schedule-cell observations. The schedule is not silently joined to the
component list. The scan contains visible conflicts: `IM101` has different
titles across the tables; `PF101` has different titles; the component table
lists `IPT102` while the schedule shows `IPT101`; and `HCI102` has no units in
Section 9.1 and no confidently matching scheduled row. These are left for
academic review. No codes, units or placements have been inferred to make the
records fit the application schema.

The 13 Section 6.3 BSIT “Graduate Outcomes” are recorded separately with their
original identifiers. No subject/outcome mappings are included. The outcome
phrasing is transcribed from printed pages 8–9 and remains subject to academic
review of the scanned source.

The SHA-256 fingerprint documents which local bytes were inspected. It does not
independently establish authenticity, current legal applicability, amendments,
or BCP adoption. This package labels the material as a **CHED sample** and
states **Institutional adoption/approval not verified**. The other listed CHED
documents were not locally available for inspection and are not treated as
supporting transcribed evidence here.

Run the source-package check from the repository root:

```powershell
php tests/ched-cmo25-dataset.php
```

This is a read-only file validation. It does not connect to a database. Import
is deliberately disabled while source-internal identity conflicts and the
missing HCI102 unit value remain unresolved. A complete immutable reference
must not be classified by omitting those facts or inventing replacement values.
This is also a concrete schema limitation: `subjects.subject_code` is unique
and required, `subjects.units` is required, and Task 3 classification captures
those fields into a complete immutable snapshot before setting
`version_role='reference'`. A conflicting code cannot be represented as two
verified identities, and a blank source unit cannot be supplied as a faithful
numeric database value. No migration or ordinary import endpoint was added to
bypass these constraints.
