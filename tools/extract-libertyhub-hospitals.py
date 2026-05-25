#!/usr/bin/env python3
"""
One-off extractor: parse the libertyhub.ng hospital list (a TablePress
table with columns S/N | Provider Name | Address | State | City/Town |
LGA | Category | Service Type) and emit a CSV that matches the MatrixPro
hospital importer schema (name, state, address, notes, display_order,
status).

Notes column is composed from City/Town, LGA, Category and Service Type
so operators reviewing the list in admin still see the granular metadata
that the user-facing dropdown doesn't surface.
"""
import csv
import html
import os
import re
import sys
from html.parser import HTMLParser

# Resolve paths relative to this script so the documented `python3
# tools/extract-libertyhub-hospitals.py` invocation from the repo root
# works without arguments.
HERE = os.path.dirname(os.path.abspath(__file__))
SRC_HTML = os.environ.get("LIBERTYHUB_SRC", "/tmp/hospital-list.html")
OUT_CSV = os.path.join(HERE, "hospital-list-libertyhub.csv")

# State whitelist mirrored from Matrix_MLM_User_Healthcare::NIGERIAN_STATES.
# The importer's normalise_state_name() also accepts FCT/Abuja aliases and
# de-dashes "Akwa-Ibom" etc, but we still emit canonical forms here so the
# CSV is unambiguous on review.
NIGERIAN_STATES = {
    'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa',
    'Benue', 'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo',
    'Ekiti', 'Enugu', 'FCT - Abuja', 'Gombe', 'Imo', 'Jigawa',
    'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara',
    'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo',
    'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara',
}

STATE_ALIASES = {
    'fct': 'FCT - Abuja',
    'abuja': 'FCT - Abuja',
    'fct-abuja': 'FCT - Abuja',
    'fct abuja': 'FCT - Abuja',
    'federal capital territory': 'FCT - Abuja',
    'akwa-ibom': 'Akwa Ibom',
    'akwaibom': 'Akwa Ibom',
    'cross-river': 'Cross River',
    'crossriver': 'Cross River',
    'cross-rivers': 'Cross River',
    'cross rivers': 'Cross River',
    'crossrivers': 'Cross River',
    'rivers state': 'Rivers',
    'lagos state': 'Lagos',
}


def normalise_state(raw: str):
    """Return canonical state string or None if unrecognised."""
    s = raw.strip()
    if not s:
        return None
    # Direct match against the canonical set, case-insensitive
    for canon in NIGERIAN_STATES:
        if canon.lower() == s.lower():
            return canon
    key = re.sub(r'\s+', ' ', s.lower())
    if key in STATE_ALIASES:
        return STATE_ALIASES[key]
    # Try removing dashes/underscores -> spaces and re-match.
    key2 = re.sub(r'\s+', ' ', re.sub(r'[-_]', ' ', s.lower()))
    for canon in NIGERIAN_STATES:
        if re.sub(r'\s+', ' ', re.sub(r'[-_]', ' ', canon.lower())) == key2:
            return canon
    if key2 in STATE_ALIASES:
        return STATE_ALIASES[key2]
    return None


class TableExtractor(HTMLParser):
    """Pull rows out of <table id="tablepress-4">, collecting cell text."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.in_target_table = False
        self.in_tr = False
        self.in_td = False
        self.current_cells = []
        self.current_text = []
        self.rows = []

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'table' and a.get('id') == 'tablepress-4':
            self.in_target_table = True
        elif self.in_target_table:
            if tag == 'tr':
                self.in_tr = True
                self.current_cells = []
            elif tag in ('td', 'th') and self.in_tr:
                self.in_td = True
                self.current_text = []

    def handle_endtag(self, tag):
        if tag == 'table' and self.in_target_table:
            self.in_target_table = False
        elif self.in_target_table:
            if tag in ('td', 'th') and self.in_td:
                text = ''.join(self.current_text).strip()
                # Collapse whitespace
                text = re.sub(r'\s+', ' ', text)
                self.current_cells.append(text)
                self.in_td = False
            elif tag == 'tr' and self.in_tr:
                if self.current_cells:
                    self.rows.append(self.current_cells)
                self.in_tr = False

    def handle_data(self, data):
        if self.in_td:
            self.current_text.append(data)


def main():
    with open(SRC_HTML, encoding='utf-8') as f:
        html_text = f.read()

    parser = TableExtractor()
    parser.feed(html_text)
    rows = parser.rows
    print(f"Total rows extracted from table: {len(rows)}", file=sys.stderr)

    if not rows:
        print("No rows extracted — table not found or shape changed.", file=sys.stderr)
        sys.exit(1)

    header = rows[0]
    print(f"Header: {header}", file=sys.stderr)
    # Expect: S/N | Provider Name | Address | State | City/Town | LGA | Category | Service Type
    body = rows[1:]

    # Drop regional separator rows (where Address+State+Category are all blank).
    cleaned = []
    for r in body:
        # Pad to 8 columns in case some rows have fewer cells.
        while len(r) < 8:
            r.append('')
        provider = r[1].strip()
        address = r[2].strip()
        state = r[3].strip()
        category = r[6].strip()
        if not provider:
            continue
        # Separator rows: provider has content but address+state+category are all empty.
        if not address and not state and not category:
            continue
        cleaned.append(r)

    print(f"After dropping separators: {len(cleaned)}", file=sys.stderr)

    # Build CSV rows; deduplicate on (name_lower, state) within source so we
    # don't ship multiple identical entries that differ only by service type.
    out = []
    seen = set()
    unknown_states = {}
    for r in cleaned:
        provider = r[1].strip()
        address = r[2].strip()
        raw_state = r[3].strip()
        city = r[4].strip()
        lga = r[5].strip()
        category = r[6].strip()
        service = r[7].strip() if len(r) > 7 else ''

        canon_state = normalise_state(raw_state)
        if canon_state is None:
            unknown_states.setdefault(raw_state, 0)
            unknown_states[raw_state] += 1
            continue

        # Truncate name to 200 chars (validator limit).
        if len(provider) > 200:
            provider = provider[:200].rstrip()
        # Truncate address to 500 chars.
        if len(address) > 500:
            address = address[:500].rstrip()

        # Compose notes from the granular metadata. Skip empty parts
        # so we don't end up with " | | | " strings.
        meta_parts = []
        if city:
            meta_parts.append(f"City/Town: {city}")
        if lga:
            meta_parts.append(f"LGA: {lga}")
        if category:
            meta_parts.append(f"Category: {category}")
        if service:
            meta_parts.append(f"Service: {service}")
        notes = ' | '.join(meta_parts)

        key = (provider.lower(), canon_state)
        if key in seen:
            # Already saw this provider in this state — merge the
            # service info into the existing notes if it's new.
            for existing in out:
                if (existing['name'].lower(), existing['state']) == key:
                    if service and (not existing['notes'] or service not in existing['notes']):
                        # Append additional service lines
                        if existing['notes']:
                            existing['notes'] = existing['notes'] + f"; also: {service}"
                        else:
                            existing['notes'] = f"Service: {service}"
                        # Cap notes column to a reasonable length
                        if len(existing['notes']) > 1000:
                            existing['notes'] = existing['notes'][:1000].rstrip()
                    break
            continue

        seen.add(key)
        out.append({
            'name': provider,
            'state': canon_state,
            'address': address,
            'notes': notes,
            'display_order': '0',
            'status': 'active',
        })

    print(f"Final unique (name,state) rows: {len(out)}", file=sys.stderr)
    if unknown_states:
        print(f"Skipped rows with unknown state values: {dict(sorted(unknown_states.items()))}", file=sys.stderr)

    # State histogram so we can sanity-check coverage in the PR description.
    by_state = {}
    for row in out:
        by_state[row['state']] = by_state.get(row['state'], 0) + 1
    print("Per-state counts:", file=sys.stderr)
    for st, n in sorted(by_state.items(), key=lambda x: (-x[1], x[0])):
        print(f"  {st}: {n}", file=sys.stderr)

    # Write CSV with UTF-8 BOM (importer strips it; Excel needs it)
    os.makedirs(os.path.dirname(OUT_CSV), exist_ok=True)
    with open(OUT_CSV, 'w', encoding='utf-8-sig', newline='') as f:
        writer = csv.DictWriter(
            f,
            fieldnames=['name', 'state', 'address', 'notes', 'display_order', 'status'],
            quoting=csv.QUOTE_MINIMAL,
        )
        writer.writeheader()
        for row in out:
            writer.writerow(row)
    print(f"Wrote {OUT_CSV}", file=sys.stderr)


if __name__ == '__main__':
    main()
