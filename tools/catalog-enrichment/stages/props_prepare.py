#!/usr/bin/env python3
"""props_prepare <run dir> [--all] [--batches N]

Writes <run>/props/in/batch-<n>.json for the agents that read products (spec: props_SPEC.md).
Only active top-level products whose name/description changed since the last finished run are
included, unless --all. Read-only on the shop.
"""
import argparse
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'lib'))
from catalog import Catalog, content_hash, in_scope, load_state, strip_html  # noqa: E402

ap = argparse.ArgumentParser()
ap.add_argument('run')
ap.add_argument('--all', action='store_true', help='ignore content-hashes.json, read every product')
ap.add_argument('--batches', type=int, default=8)
ap.add_argument('--scope', choices=['active', 'inactive', 'all'], default='active')
ap.add_argument('--append', action='store_true', help='add batches after the existing ones (e.g. a second, inactive pass)')
a = ap.parse_args()

c = Catalog(a.run)
hashes = {} if a.all else load_state('content-hashes.json', {})
items = []
for p in c.products:
    if not in_scope(p, a.scope):
        continue
    if hashes.get(p['number']) == content_hash(p):
        continue
    mc = c.main_category(p)
    items.append({
        'id': p['id'], 'number': p['number'], 'kind': p['kind'], 'name': p['name'],
        'short_description': strip_html(p['short_description']) or None,
        'description': strip_html(p['description']) or None,
        'category': ' > '.join(x['name'] for x in c.cat_path(mc)) if mc else None,
        'existing_properties': sorted(f'{g}: {v}' for g, vs in c.existing(p).items() for v in vs),
    })

out = os.path.join(a.run, 'props', 'in')
os.makedirs(out, exist_ok=True)
os.makedirs(os.path.join(a.run, 'props', 'out'), exist_ok=True)
allp = os.path.join(a.run, 'props', 'all.json')
first = 1
if a.append and os.path.exists(allp):
    known = json.load(open(allp, encoding='utf-8'))
    seen = {x['id'] for x in known}
    items = [x for x in items if x['id'] not in seen]
    json.dump(known + items, open(allp, 'w', encoding='utf-8'), ensure_ascii=False)
    first = 1 + max([int(f[6:-5]) for f in os.listdir(out) if f.startswith('batch-')] or [0])
else:
    for f in os.listdir(out):
        os.remove(os.path.join(out, f))
    json.dump(items, open(allp, 'w', encoding='utf-8'), ensure_ascii=False)
n = max(1, min(a.batches, len(items)))
size = -(-len(items) // n) if items else 0
for i in range(n if items else 0):
    chunk = items[i * size:(i + 1) * size]
    if chunk:
        json.dump(chunk, open(os.path.join(out, f'batch-{first + i}.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print(f'{len(items)} products to read ({"all" if a.all else "new or changed"}), '
      f'{len(os.listdir(out))} batches in {out}')
