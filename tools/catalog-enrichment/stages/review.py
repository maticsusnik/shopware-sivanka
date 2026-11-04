#!/usr/bin/env python3
"""review <review.csv> [...] : store the `decision` column of edited review files in state/decisions.json.

Accepted values (case-insensitive): approve / a / da / yes / y  and  reject / r / ne / no / n.
An empty cell leaves an earlier decision as it is; "-" deletes it. The file is keyed by product
number, so a decision made today still applies to the same product in next year's dump.
"""
import datetime
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'lib'))
from catalog import load_state, read_csv, save_state  # noqa: E402

YES, NO = {'approve', 'a', 'da', 'yes', 'y'}, {'reject', 'r', 'ne', 'no', 'n'}
decisions = load_state('decisions.json', {})
today = datetime.date.today().isoformat()
changed = bad = 0
for path in sys.argv[1:]:
    for r in read_csv(path):
        key, d = r.get('key'), (r.get('decision') or '').strip().casefold()
        if not key or not d:
            continue
        if d == '-':
            changed += decisions.pop(key, None) is not None
            continue
        if d not in YES | NO:
            bad += 1
            print(f'unknown decision {d!r} for {key}', file=sys.stderr)
            continue
        new = 'approve' if d in YES else 'reject'
        if decisions.get(key, {}).get('decision') != new:
            decisions[key] = {'decision': new, 'date': today, 'note': (r.get('note') or '').strip()}
            changed += 1
save_state('decisions.json', decisions)
print(f'{changed} decisions stored, {bad} unreadable, {len(decisions)} in total')
