#!/usr/bin/env python3
"""categories_prepare <run dir> [--batches N] : products without any category -> categories/in/ (spec: categories_SPEC.md)."""
import argparse
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'lib'))
from catalog import Catalog, in_scope, strip_html  # noqa: E402

ap = argparse.ArgumentParser()
ap.add_argument('run')
ap.add_argument('--batches', type=int, default=8)
a = ap.parse_args()
c = Catalog(a.run)


def product_tree(cat):
    """Product-listing categories below Izdelki (pages), as id -> 'A > B > C'."""
    out = {}
    for x in cat.categories:
        path = cat.cat_path(x)
        if x['type'] == 'page' and any(y['name'] == 'Izdelki' for y in path[:-1]):
            out[x['id']] = ' > '.join(y['name'] for y in path if y['name'] != 'Izdelki' and y['level'] > 1)
    return out


tree = product_tree(c)
items = [{'id': p['id'], 'number': p['number'], 'name': p['name'], 'active': bool(p['active']),
          'short_description': (strip_html(p['short_description']) or '')[:300] or None,
          'description': (strip_html(p['description']) or '')[:300] or None,
          'properties': sorted(f'{g}: {v}' for g, vs in c.existing(p).items() for v in vs)}
         for p in c.products if in_scope(p, 'all') and not p['categories']]
d = os.path.join(a.run, 'categories', 'in')
os.makedirs(d, exist_ok=True)
os.makedirs(os.path.join(a.run, 'categories', 'out'), exist_ok=True)
for f in os.listdir(d):
    os.remove(os.path.join(d, f))
json.dump(items, open(os.path.join(a.run, 'categories', 'all.json'), 'w', encoding='utf-8'), ensure_ascii=False)
cats = [{'id': k, 'path': v} for k, v in sorted(tree.items(), key=lambda x: x[1])]
n = max(1, min(a.batches, len(items)))
size = -(-len(items) // n) if items else 0
for i in range(n if items else 0):
    chunk = items[i * size:(i + 1) * size]
    if chunk:
        json.dump({'categories': cats, 'products': chunk}, open(os.path.join(d, f'batch-{i + 1}.json'), 'w', encoding='utf-8'),
                  ensure_ascii=False, indent=1)
print(f'{len(items)} products without a category, {len(cats)} product categories, {len(os.listdir(d))} batches')
