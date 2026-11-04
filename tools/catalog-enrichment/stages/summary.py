#!/usr/bin/env python3
"""summary <run dir> : read-only overview of the catalogue and its content gaps -> <run>/summary.md"""
import collections
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'lib'))
from catalog import Catalog, content_hash, load_state, strip_html  # noqa: E402

run = sys.argv[1]
c = Catalog(run)
P = c.products
top = [p for p in P if p['kind'] != 'variant']
active = [p for p in top if p['active'] or p['kind'] == 'parent']
hashes = load_state('content-hashes.json', {})

kinds = collections.Counter(p['kind'] for p in P)
gap = collections.Counter()
for p in active:
    gap['no description'] += not strip_html(p['description'])
    gap['no short description'] += not strip_html(p['short_description'])
    gap['no cover image'] += not p['cover_hash']
    gap['no category'] += not p['categories']
    gap['no manufacturer'] += not p['manufacturer']
    gap['no properties'] += not p['properties']
    gap['not in a sales channel'] += not p['visibilities']
    gap['new or changed since last run'] += hashes.get(p['number']) != content_hash(p)
shared = collections.Counter(p['cover_hash'] for p in active if p['cover_hash'])
gap['share their cover image with another product'] = sum(n for n in shared.values() if n > 1)

L = [f'# Catalogue summary ({os.path.basename(run)})', '',
     f'{len(P)} products: {kinds["simple"]} simple, {kinds["parent"]} parents, {kinds["variant"]} variants. '
     f'{len(active)} active top-level products (the base for every percentage below).', '',
     '| Gap | Products | Share |', '|---|---:|---:|']
L += [f'| {k} | {v} | {100 * v / max(len(active), 1):.0f} % |' for k, v in gap.items()]
L += ['', f'{len(c.options)} property options in {len(c.groups)} groups, {len(c.categories)} categories.', '']
open(os.path.join(run, 'summary.md'), 'w', encoding='utf-8').write('\n'.join(L))
print('\n'.join(L))
