#!/usr/bin/env python3
"""
update_order_tracking.py
━━━━━━━━━━━━━━━━━━━━━━━━
Merges tracking data from the Wegmans Order Guide spreadsheet
into the Muse Order History JSON archive.

USAGE
  python update_order_tracking.py [XLSX_PATH] [JSON_PATH] [OUTPUT_PATH]

  All three arguments are optional — defaults point to the plugin's
  expected file locations (relative to this script's directory).

SPREADSHEET EXPECTATIONS
  The xlsx must have this column layout (row 1 = header, data from row 2):
    A  = Order #         (integer)
    B  = Order Status
    C  = Latest Tracking (tracking number, or supplier name if not real)
    I  = Has Tracking    ("yes" / "no" — col I, index 8)
    J  = Carrier         ("UPS" / "FEDEX" / "USPS" / "Other")

  Multiple tracking numbers in col C: separate with comma or ampersand.
  Merged/multi-line cells (two POs in one row): use newline inside cell.
  A tracking entry is only used if col I is "yes" AND col C contains
  at least 8 consecutive digits (filters out supplier name placeholders).

WHAT IT DOES
  - Reads every row, groups by order number (deduplicates rows)
  - For each order in the JSON that also appears in the xlsx:
      → appends any tracking entries not already present
  - Orders in JSON but not in xlsx are left untouched
  - Never removes existing tracking entries
  - Writes the result to OUTPUT_PATH (defaults to overwriting the JSON)

RUNNING REGULARLY
  Drop a new xlsx into the same folder and re-run. The script is safe
  to run repeatedly — it won't duplicate entries.
"""

import sys
import re
import json
import openpyxl
from pathlib import Path
from collections import defaultdict

# ── Carrier tracking URL templates ──────────────────────────────────────────

CARRIER_URLS = {
    'UPS':   'https://www.ups.com/track?tracknum={n}',
    'FEDEX': 'https://www.fedex.com/fedextrack/?trknbr={n}',
    'USPS':  'https://tools.usps.com/go/TrackConfirmAction?tLabels={n}',
}

# ── Helpers ──────────────────────────────────────────────────────────────────

def is_real_tracking_number(s: str) -> bool:
    """Return True only if the string contains 8+ consecutive digits.
    Filters out supplier names, notes, and placeholder text."""
    return bool(re.search(r'\d{8,}', s))


def build_tracking_entries(tracking_raw, carrier_raw, has_tracking_raw) -> list:
    """Parse one spreadsheet row's tracking columns into a list of dicts.

    Handles:
      - Newline-merged cells  (two POs stacked in one cell)
      - Comma/ampersand lists (multiple tracking numbers in one cell)
      - 'yy' typo for 'yes'
      - Non-real tracking numbers (supplier names) — silently skipped
    """
    entries = []

    def split_nl(val):
        return [x.strip() for x in str(val).split('\n')] if val else ['']

    ht_parts = split_nl(has_tracking_raw)
    tr_parts = split_nl(tracking_raw)
    ca_parts = split_nl(carrier_raw)

    # Pad to equal length
    n = max(len(ht_parts), len(tr_parts), len(ca_parts))
    ht_parts += [''] * (n - len(ht_parts))
    tr_parts += [''] * (n - len(tr_parts))
    ca_parts += [''] * (n - len(ca_parts))

    for ht, tr, ca in zip(ht_parts, tr_parts, ca_parts):
        if ht.lower().strip() not in ('yes', 'yy'):
            continue
        if not tr:
            continue

        ca_clean = ca.strip().upper()
        # Split comma/ampersand-separated numbers
        nums = [x.strip() for x in re.split(r'\s*[,&]\s*', tr) if x.strip()]

        for n in nums:
            if not is_real_tracking_number(n):
                continue
            url_tmpl = CARRIER_URLS.get(ca_clean, '')
            url = url_tmpl.replace('{n}', n) if url_tmpl else ''
            entry = {
                'carrier': ca_clean if ca_clean else 'OTHER',
                'number':  n,
                'url':     url,
            }
            if entry not in entries:
                entries.append(entry)

    return entries


# ── Core merge logic ─────────────────────────────────────────────────────────

def parse_spreadsheet(xlsx_path: Path) -> dict:
    """Return a dict of {order_number: {'status': str, 'tracking': [...]}}."""
    wb = openpyxl.load_workbook(xlsx_path, read_only=True)
    ws = wb.active
    rows = list(ws.iter_rows(values_only=True))

    orders = defaultdict(lambda: {'status': None, 'tracking': [], '_seen': set()})

    for r in rows[1:]:          # row 0 is header
        if not r[0] or not isinstance(r[0], int):
            continue
        order_num = int(r[0])
        d = orders[order_num]
        if r[1]:
            d['status'] = str(r[1]).strip()

        entries = build_tracking_entries(r[2], r[9], r[8])
        for e in entries:
            key = (e['carrier'], e['number'])
            if key not in d['_seen']:
                d['_seen'].add(key)
                d['tracking'].append(e)

    # Drop internal dedup set before returning
    for d in orders.values():
        del d['_seen']

    return dict(orders)


def merge(json_orders: list, xlsx_orders: dict) -> dict:
    """Merge xlsx tracking into json_orders in place. Returns stats dict."""
    stats = {
        'orders_updated':     0,
        'orders_already_had': 0,
        'orders_not_in_xlsx': 0,
        'entries_added':      0,
    }

    for order in json_orders:
        num = order['order_number']

        if num not in xlsx_orders:
            stats['orders_not_in_xlsx'] += 1
            continue

        xlsx = xlsx_orders[num]
        existing_keys = {(t['carrier'], t['number']) for t in order.get('tracking', [])}
        new_entries   = [e for e in xlsx['tracking'] if (e['carrier'], e['number']) not in existing_keys]

        if new_entries:
            order.setdefault('tracking', []).extend(new_entries)
            stats['orders_updated'] += 1
            stats['entries_added']  += len(new_entries)
        elif xlsx['tracking']:
            stats['orders_already_had'] += 1

    return stats


# ── Entry point ──────────────────────────────────────────────────────────────

def main():
    script_dir = Path(__file__).parent

    xlsx_path   = Path(sys.argv[1]) if len(sys.argv) > 1 else script_dir / 'Order_Guide_Tracking.xlsx'
    json_path   = Path(sys.argv[2]) if len(sys.argv) > 2 else script_dir / 'data' / 'wegmans_order_history.json'
    output_path = Path(sys.argv[3]) if len(sys.argv) > 3 else json_path   # overwrite by default

    # Validate inputs
    if not xlsx_path.exists():
        print(f"ERROR: xlsx not found: {xlsx_path}")
        sys.exit(1)
    if not json_path.exists():
        print(f"ERROR: JSON not found: {json_path}")
        sys.exit(1)

    print(f"Reading spreadsheet: {xlsx_path.name}")
    xlsx_orders = parse_spreadsheet(xlsx_path)
    print(f"  {len(xlsx_orders)} unique orders parsed")
    print(f"  {sum(1 for v in xlsx_orders.values() if v['tracking'])} have tracking")

    print(f"\nReading JSON archive: {json_path.name}")
    with open(json_path, encoding='utf-8') as f:
        json_orders = json.load(f)
    print(f"  {len(json_orders)} orders loaded")

    print("\nMerging...")
    stats = merge(json_orders, xlsx_orders)

    print(f"\n── Results ─────────────────────────────────────")
    print(f"  Orders updated (tracking added):  {stats['orders_updated']}")
    print(f"  Orders already had tracking:      {stats['orders_already_had']}")
    print(f"  Orders not in spreadsheet:        {stats['orders_not_in_xlsx']}")
    print(f"  Total new tracking entries added: {stats['entries_added']}")

    print(f"\nWriting output: {output_path}")
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(json_orders, f, indent=2, ensure_ascii=False)

    print("Done. ✓")


if __name__ == '__main__':
    main()
