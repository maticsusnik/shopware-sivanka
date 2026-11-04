#!/usr/bin/env python3
"""seo_prepare <run dir> [--gsc <Search Console export .zip>] [--batches N] [--only-changed]

Writes <run>/seo/in/batch-<n>.json for the agents (spec: seo_SPEC.md). By default every active
top-level product is included: Matic asked for all SEO to be rewritten (2026-11-03).
--gsc: the Search Console "Performance on Search" export; its Queries.csv becomes `search_terms`
(kept in <run>/gsc/). Without it, the run's existing gsc/Queries.csv is used if present.
"""
import argparse
import csv
import io
import json
import os
import sys
import zipfile

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'lib'))
from catalog import Catalog, content_hash, in_scope, load_state, strip_html  # noqa: E402

ap = argparse.ArgumentParser()
ap.add_argument('run')
ap.add_argument('--gsc')
ap.add_argument('--batches', type=int, default=10)
ap.add_argument('--scope', choices=['active', 'inactive', 'all'], default='active')
ap.add_argument('--append', action='store_true', help='add batches after the existing ones (e.g. a second, inactive pass)')
ap.add_argument('--only-changed', action='store_true')
a = ap.parse_args()

gdir = os.path.join(a.run, 'gsc')
if a.gsc:
    os.makedirs(gdir, exist_ok=True)
    with zipfile.ZipFile(a.gsc) as z:
        for n in z.namelist():
            if n.endswith('.csv') and '/' not in n and '..' not in n:
                open(os.path.join(gdir, n), 'wb').write(z.read(n))
terms = []
qpath = os.path.join(gdir, 'Queries.csv')
if os.path.exists(qpath):
    for r in csv.DictReader(io.open(qpath, encoding='utf-8-sig')):
        imp = int(r.get('Impressions') or 0)
        q = (r.get('Top queries') or '').strip()
        if imp >= 3 and q and 'kranj' not in q and 'sivanka' not in q.replace('š', 's'):
            terms.append([q, imp])
    terms.sort(key=lambda x: -x[1])

c = Catalog(a.run)
hashes = load_state('seo-hashes.json', {}) if a.only_changed else {}
items = []
for p in c.products:
    if not in_scope(p, a.scope):
        continue
    if hashes.get(p['number']) == content_hash(p):
        continue
    mc = c.main_category(p)
    items.append({
        'id': p['id'], 'number': p['number'], 'name': p['name'],
        'short_description': strip_html(p['short_description']) or None,
        'description': strip_html(p['description'])[:4000] or None,
        'category': ' > '.join(x['name'] for x in c.cat_path(mc)) if mc else None,
        'properties': sorted(f'{g}: {v}' for g, vs in c.existing(p).items() for v in vs),
        'current': {k: p[k] for k in ('meta_title', 'meta_description', 'keywords')},
    })

out = os.path.join(a.run, 'seo', 'in')
os.makedirs(out, exist_ok=True)
os.makedirs(os.path.join(a.run, 'seo', 'out'), exist_ok=True)
allp = os.path.join(a.run, 'seo', 'all.json')
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
        json.dump({'search_terms': terms, 'products': chunk},
                  open(os.path.join(out, f'batch-{first + i}.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print(f'{len(items)} products, {len(os.listdir(out))} batches, {len(terms)} search terms')
