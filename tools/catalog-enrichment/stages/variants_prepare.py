#!/usr/bin/env python3
"""variants_prepare <run dir> [--batches N] [--scope active|all] : candidate clusters -> variants/in/.

A product's *stem* is its name without what usually varies inside a family: numbers with units (mm, cm,
m, g, ml, l, kos, par …), sizes, colour words, shade/model codes (B.1009, N3, 6CF), "ali", brackets.
Products with the same stem form a cluster; stems that differ by a single word (a colour the list does not
know, a typo such as CEVAST/CEVASTI) are merged as long as the cluster stays small.

Existing variants take part too: a variant carries `family` (its parent, groups and option values), and all
variants of one parent always land in the same cluster. A cluster goes to the agents when it holds at least
one standalone product, or two existing families (merge candidates). The agents (variants_SPEC.md) decide
which products are one product in versions; they may extend or merge existing families.
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
ap.add_argument('--batches', type=int, default=16)
ap.add_argument('--scope', choices=['active', 'all'], default='all', help='all = inactive products too')
ap.add_argument('--max', type=int, default=80, help='largest cluster')
a = ap.parse_args()
c = Catalog(a.run)

COLOURS = (r'ČRN|BEL|RDEČ|MODR|MODER|ZELEN|RUMEN|VIJOLIČN|RJAV|SIV|ROZA|ROZ|ORANŽN|BEŽ|KREM|ZLAT|SREBRN|BRONZ|NIKE?LJ?|'
           r'STARO|PROZOR|TRANSPARENT|BORDO|TEMNO|SVETLO|NARAVN|MAT|SVETLEČ|PISAN|ČRNO|BELO|TURKIZ|LILA|OKER|KAKI|'
           r'MARINE|PETROL|BRAON|ANTRACIT|GRAFIT|ZLATO|SREBRNO|SMETAN|BAKREN|CARAMEL|NEUTRAL|BRONZATO')
UNIT = r'(?:MM|CM|DM|M|G|GR|KG|MG|ML|DL|CL|L|LIT|KOS|KOSOV|KOSI|KOM|PAR|PARI|KPL|M2|YD|NM|")'


def stem(name):
    s = ' ' + (name or '').upper() + ' '
    s = re.sub(r'(?<=[A-ZČŠŽ])(?=\d)|(?<=\d)(?=[A-ZČŠŽ]{3,})', ' ', s)            # STEP4 -> STEP 4, 70CMTEMNO
    s = re.sub(r'[()\[\],;/+]', ' ', s)
    s = re.sub(r'\b(?:B|BR|ŠT|ART|N|NR)\.?\s?\d+[A-Z]?\b', ' ', s)              # B.1009, ŠT.4, N3
    s = re.sub(r'\d+(?:[.,]\d+)?(?:\s?[X×-]\s?\d+(?:[.,]\d+)?)*\s?' + UNIT + r'?(?![A-ZČŠŽ0-9])', ' ', s)  # 16CM, 1L, 40X30
    s = re.sub(r'\b(?:' + COLOURS + r')[A-ZČŠŽ]*\b', ' ', s)
    s = re.sub(r'\b(?:ALI|IN|Z|ZA|S|V|NA|-|BARVA|BARVE)\b', ' ', s)
    s = re.sub(r'\b[A-Z]{1,2}\d*\b|\b\d+\b|[.\-]', ' ', s)                        # short codes, bare numbers
    return ' '.join(s.split())


def family_of(p):
    """Existing family of a variant: parent id/name/groups and this variant's option values."""
    if p['kind'] != 'variant':
        return None
    parent = c.by_id.get(p['parent'])
    opts = {}
    for oid in p['options']:
        o = c.opt.get(oid)
        if o:
            opts[o['group']] = o['name']
    return {'parent_id': p['parent'], 'parent_number': parent['number'] if parent else None,
            'parent_name': (parent or {}).get('name'), 'options': opts}


pool = [p for p in c.products if p['name'] and p['kind'] in ('simple', 'variant')
        and (a.scope == 'all' or p['active'] or p['kind'] == 'variant')]
# a whole existing family clusters as one unit, under the stem most of its members have
unit_of, units = {}, collections.defaultdict(list)
for p in pool:
    key = ('fam', p['parent']) if p['kind'] == 'variant' else ('p', p['id'])
    units[key].append(p)
unit_stem = {}
for key, ps in units.items():
    stems = collections.Counter(stem(p['name']) for p in ps)
    unit_stem[key] = stems.most_common(1)[0][0]

by_stem = collections.defaultdict(list)
for key, st in unit_stem.items():
    if len(st) >= 4:
        by_stem[st].append(key)

# merge stems that differ by one word (same first word, >= 2 shared words), smallest clusters first
parent = {st: st for st in by_stem}


def find(x):
    while parent[x] != x:
        parent[x] = parent[parent[x]]
        x = parent[x]
    return x


size = {st: sum(len(units[k]) for k in ks) for st, ks in by_stem.items()}
toks = {st: st.split() for st in by_stem}


def merge(x, y):
    rx, ry = find(x), find(y)
    if rx == ry:
        return
    sx = sum(size[s] for s in by_stem if find(s) == rx)
    sy = sum(size[s] for s in by_stem if find(s) == ry)
    if sx + sy <= a.max or min(sx, sy) <= 10:      # a small group may always join a bigger one
        parent[ry] = rx


# 1) same first word, one word different (unknown colour word, typo: CEVAST / CEVASTI)
by_first = collections.defaultdict(list)
for st in by_stem:
    by_first[toks[st][0]].append(st)
for first, sts in by_first.items():
    sts.sort(key=lambda s: size[s])
    for i, x in enumerate(sts):
        for y in sts[i + 1:]:
            tx, ty = set(toks[x]), set(toks[y])
            if len(tx & ty) >= 2 and len(tx ^ ty) <= 1 + (abs(len(tx) - len(ty)) == 0):
                merge(x, y)
# 2) one stem is the other plus one word in front or anywhere (AUSTERMANN STEP PREJA … / STEP PREJA …)
by_set = {frozenset(toks[st]): st for st in by_stem}
for st in by_stem:
    t = set(toks[st])
    if len(t) >= 4:
        for w in t:
            other = by_set.get(frozenset(t - {w}))
            if other:
                merge(other, st)

clusters = collections.defaultdict(list)
for st, keys in by_stem.items():
    clusters[find(st)].extend(keys)

out_clusters = []
for i, (st, keys) in enumerate(sorted(clusters.items())):
    ps = [p for k in keys for p in units[k]]
    standalone = sum(1 for k in keys if k[0] == 'p')
    families = sum(1 for k in keys if k[0] == 'fam')
    if not (standalone >= 1 and len(keys) >= 2 or families >= 2) or len(ps) > a.max * 2:
        continue
    out_clusters.append({'cluster': f'c-{i}', 'stem': st, 'products': [{
        'id': p['id'], 'number': p['number'], 'name': p['name'], 'active': bool(p['active']),
        'family': family_of(p),
        'price': (p['price_gross'] or (c.by_id.get(p['parent'] or '', {}).get('price_gross')) or [None])[0],
        'stock': p['stock'],
        'category': ' > '.join(x['name'] for x in c.cat_path(c.main_category(p))) if c.main_category(p) else None,
        'short_description': (strip_html(p['short_description']) or '')[:300] or None,
        'description': (strip_html(p['description']) or '')[:300] or None,
        'cover': (p['cover_hash'] or '')[:10] or None,
        'properties': sorted(f'{g}: {v}' for g, vs in c.existing(p).items() for v in vs),
    } for p in sorted(ps, key=lambda p: (p['parent'] or '', p['number']))]})

d = os.path.join(a.run, 'variants', 'in')
os.makedirs(d, exist_ok=True)
os.makedirs(os.path.join(a.run, 'variants', 'out'), exist_ok=True)
for f in os.listdir(d):
    os.remove(os.path.join(d, f))
json.dump(out_clusters, open(os.path.join(a.run, 'variants', 'clusters.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
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
fam_clusters = sum(1 for x in out_clusters if any(p['family'] for p in x['products']))
print(f'{len(out_clusters)} candidate clusters with {n} products ({fam_clusters} touch an existing family), '
      f'{sum(1 for b in batches if b)} batches')
