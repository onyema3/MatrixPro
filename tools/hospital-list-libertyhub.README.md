# LibertyHub Hospital List Import

**Source:** https://libertyhub.ng/hospital-list/ (TablePress table id `tablepress-4`)

**Output:** `hospital-list-libertyhub.csv` — 1,862 hospitals across 36 states + FCT, deduped on `(name, state)`.

## What's in the CSV

Each row maps the source provider table to the columns the
`Matrix_MLM_Admin_Hospitals` importer expects:

| CSV column       | Source                                                        |
|------------------|---------------------------------------------------------------|
| `name`           | Provider Name                                                 |
| `state`          | State (normalised to `NIGERIAN_STATES` whitelist)             |
| `address`        | Address                                                       |
| `notes`          | `City/Town: ... | LGA: ... | Category: ... | Service: ...`    |
| `display_order`  | `0` (alphabetic ordering within state takes over)             |
| `status`         | `active`                                                      |

Where the same hospital appears multiple times in the source list (one
row per service type — e.g. General, Eye, Dental), the rows are merged
into a single hospital row whose `notes` field carries the combined
service list.

State strings are pre-normalised to canonical
`Matrix_MLM_User_Healthcare::NIGERIAN_STATES` values (so `Cross-Rivers`,
`Rivers State`, `FCT`, etc. resolve to `Cross River`, `Rivers`,
`FCT - Abuja` respectively). The importer's `normalise_state_name()`
also handles aliases at upload time, so the CSV could be re-run on a
freshly scraped source without modification.

## How to import

1. **wp-admin → MatrixPro → Hospitals**
2. Click **Bulk Import Hospitals (CSV)** to expand the panel.
3. Choose `hospital-list-libertyhub.csv`.
4. Leave **Update rows where (name, state) already exists** unchecked
   for a first run (skip-duplicates is the safe default).
5. Click **Import CSV**.

The import loop runs server-side and reports per-row outcomes. Expect
roughly **1,862 added** on a clean install. On re-runs every row will
be skipped as a duplicate.

If the import times out (large lists on shared hosts with short PHP
`max_execution_time`), simply re-upload the same CSV — the
`(name, state)` natural key dedupe makes the importer idempotent, so a
partial run resumes cleanly on retry.

## Per-state coverage

```
Lagos: 645        Plateau: 30         Bauchi: 10
FCT - Abuja: 260  Niger: 28           Sokoto: 10
Rivers: 226       Anambra: 25         Gombe: 9
Ogun: 95          Ondo: 25            Bayelsa: 8
Delta: 63         Benue: 23           Borno: 8
Oyo: 59           Enugu: 22           Osun: 8
Edo: 45           Kogi: 22            Katsina: 7
Kaduna: 41        Kwara: 21           Kebbi: 6
Akwa Ibom: 36     Imo: 17             Yobe: 5
Kano: 30          Abia: 16            Zamfara: 5
                  Adamawa: 16         Ekiti: 4
                  Nasarawa: 16        Ebonyi: 3
                  Cross River: 13     Taraba: 3
                                      Jigawa: 2
```

## Re-generating the CSV

If LibertyHub publishes an updated list, regenerate with:

```bash
curl -sSL -A "Mozilla/5.0" -o /tmp/hospital-list.html https://libertyhub.ng/hospital-list/
LIBERTYHUB_SRC=/tmp/hospital-list.html python3 tools/extract-libertyhub-hospitals.py
```

(`LIBERTYHUB_SRC` defaults to `/tmp/hospital-list.html` if unset, so on
most systems just run the curl + `python3 tools/extract-libertyhub-hospitals.py`
back-to-back.)

The extractor is committed alongside the CSV at
`tools/extract-libertyhub-hospitals.py` so the next person who needs to
re-sync has a path forward without reverse-engineering the table
shape.

## What's NOT shipped

- The `Category` (Tier 1 / Tier 5 / etc.) is preserved in `notes` only.
  The plugin's hospital schema has no tier column today; if/when it
  grows one, the extractor can write it directly.
- The `Service Type` (General / ENT / Dental / Eye / Optical) is
  preserved in `notes` only and merged where a hospital appears under
  multiple service types in the source.
- Five source rows had blank state cells and were skipped. Thirty-seven
  rows were repeated section-header decorations in the source page
  (e.g. `LAGOS MAINLAND`, `RIVERS STATE`) and were correctly dropped.
