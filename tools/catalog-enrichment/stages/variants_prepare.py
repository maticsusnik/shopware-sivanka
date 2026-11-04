#!/usr/bin/env python3
"""variants_prepare <run dir> [--batches N] : candidate clusters of near-identical products -> variants/in/.

A product's *stem* is its name without what usually varies inside a family: numbers with units,
sizes, colour words, shade/model codes (B.1009, N3, 6CF), "ali", brackets. Active standalone
products with the same stem form a cluster (2..60 members). The agents (variants_SPEC.md) decide
which clusters are real families. Products already decided (state/decisions.json) are marked.
"""
import argparse
import collections
import json
import os
import re
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'lib'))
from catalog import Catalog, strip_html  # noqa: E402

ap = argparse.ArgumentParser()
ap.add_argument('run')
ap.add_argument('--batches', type=int, default=8)
ap.add_argument('--scope', choices=['active', 'all'], default='active', help='all = inactive products too')
ap.add_argument('--hints', help='accepted.json of an earlier round: members carry the family they were approved in')
a = ap.parse_args()
c = Catalog(a.run)
hint = {}
if a.hints:
    for fam in json.load(open(a.hints, encoding='utf-8')):
        for m in fam['members']:
            hint[m['id']] = fam['parent_name'] + ' [' + ', '.join(fam['groups']) + ']'

COLOURS = (r'ČRN|BEL|RDEČ|MODR|ZELEN|RUMEN|VIJOLIČN|RJAV|SIV|ROZA|ROZ|ORANŽN|BEŽ|KREM|ZLAT|SREBRN|BRONZ|NIKE?LJ?|'
           r'STARO|PROZOR|TRANSPARENT|BORDO|TEMNO|SVETLO|NARAVN|MAT|SVETLEČ|PISAN|ČRNO|BELO|TURKIZ|LILA|OKER|KAKI|'
           r'MARINE|PETROL|BRAON|ANTRACIT|GRAFIT|ZLATO|SREBRNO')
UNIT = r'(?:MM|CM|M|G|GR|KG|ML|KOS|KOM|KOSOV|M2|")'


def stem(name):
    s = ' ' + (name or '').upper() + ' '
    s = re.sub(r'[()\[\],;/+]', ' ', s)
    s = re.sub(r'\b(?:B|BR|ŠT|ART|N|NR)\.?\s?\d+[A-Z]?\b', ' ', s)              # B.1009, ŠT.4, N3
    s = re.sub(r'\d+(?:[.,]\d+)?(?:\s?[X×-]\s?\d+(?:[.,]\d+)?)*\s?' + UNIT + r'?\b', ' ', s)  # 16CM, 40X30, 2-3MM
    s = re.sub(r'\b(?:' + COLOURS + r')[A-ZČŠŽ]*\b', ' ', s)
    s = re.sub(r'\b(?:ALI|IN|Z|ZA|S|V|NA|-)\b', ' ', s)
    s = re.sub(r'\b[A-Z]{1,2}\d*\b', ' ', s)                                       # single letters / short codes
    return ' '.join(s.split())


clusters = collections.defaultdict(list)
for p in c.products:
    if p['kind'] != 'simple' or not p['name'] or (a.scope == 'active' and not p['active']):
        continue
    st = stem(p['name'])
    if len(st) >= 4:
        clusters[st].append(p)

out_clusters = []
for i, (st, ps) in enumerate(sorted(clusters.items())):
    if not 2 <= len(ps) <= 80:
        continue
    out_clusters.append({'cluster': f'c-{i}', 'stem': st, 'products': [{
        'id': p['id'], 'number': p['number'], 'name': p['name'], 'active': bool(p['active']),
        'approved_family': hint.get(p['id']),
        'price': (p['price_gross'] or [None])[0], 'stock': p['stock'],
        'category': ' > '.join(x['name'] for x in c.cat_path(c.main_category(p))) if c.main_category(p) else None,
        'short_description': (strip_html(p['short_description']) or '')[:300] or None,
        'description': (strip_html(p['description']) or '')[:300] or None,
        'cover': (p['cover_hash'] or '')[:10] or None,
        'properties': sorted(f'{g}: {v}' for g, vs in c.existing(p).items() for v in vs),
    } for p in sorted(ps, key=lambda p: p['number'])]})

d = os.path.join(a.run, 'variants', 'in')
os.makedirs(d, exist_ok=True)
os.makedirs(os.path.join(a.run, 'variants', 'out'), exist_ok=True)
for f in os.listdir(d):
    os.remove(os.path.join(d, f))
json.dump(out_clusters, open(os.path.join(a.run, 'variants', 'clusters.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
# balance batches by number of products
batches = [[] for _ in range(max(1, a.batches))]
load = [0] * len(batches)
for cl in sorted(out_clusters, key=lambda x: -len(x['products'])):
    j = load.index(min(load))
    batches[j].append(cl)
    load[j] += len(cl['products'])
for j, b in enumerate(batches):
    if b:
        json.dump(b, open(os.path.join(d, f'batch-{j + 1}.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
n = sum(len(x['products']) for x in out_clusters)
print(f'{len(out_clusters)} candidate clusters with {n} products, {sum(1 for b in batches if b)} batches')
