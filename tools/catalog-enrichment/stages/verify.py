#!/usr/bin/env python3
"""verify <run dir> <snapshot-after.tsv> : compare the product fingerprint before and after an import.

Expected: properties change only by exactly the rows in rollback/02-product-properties-added.csv,
the text hash only on products in import/03-product-seo.csv, nothing else on any product.
"""
import collections
import csv
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'lib'))
from catalog import read_csv  # noqa: E402

COLS = ['id', 'number', 'active', 'stock', 'price', 'tax', 'parent', 'children', 'cover', 'text', 'properties',
        'options', 'categories', 'visibilities', 'media']
run, after_path = sys.argv[1], sys.argv[2]


def load(path):
    return {r[0]: dict(zip(COLS, r)) for r in csv.reader(open(path, encoding='utf-8'), delimiter='\t')}


def ids(s):
    return {x for x in s.split(',') if x and x != 'NULL'}


before, after = load(os.path.join(run, 'snapshot.tsv')), load(after_path)
added = collections.defaultdict(set)
for name in ('02-product-properties-added.csv', '11-variant-properties-added.csv'):
    p = os.path.join(run, 'rollback', name)
    for r in read_csv(p) if os.path.exists(p) else []:
        added[r['product_id']].add(r['option_id'])
seo_ids = set()
p = os.path.join(run, 'import', '03-product-seo.csv')
seo_ids = {r['id'] for r in read_csv(p)} if os.path.exists(p) else set()

cat_ids, text_ids, removed = set(), set(), collections.defaultdict(set)
p = os.path.join(run, 'import', '09-product-categories.csv')
cat_ids = {r['id'] for r in read_csv(p)} if os.path.exists(p) else set()
p = os.path.join(run, 'import', '10-fix-texts.csv')
text_ids = {r['id'] for r in read_csv(p)} if os.path.exists(p) else set()
p = os.path.join(run, 'import', 'fixes-manual.json')
for r in json.load(open(p, encoding='utf-8')) if os.path.exists(p) else []:
    if r['kind'] == 'remove_property':
        removed[r['product_id']].add(r['option_id'])
    else:
        text_ids.add(r['product_id'])

p = os.path.join(run, 'import', '12-parent-covers.csv')
cover_ids = {r['id'] for r in read_csv(p)} if os.path.exists(p) else set()
children, parents_new = {}, set()
p = os.path.join(run, 'import', '07-variant-children.csv')
for r in read_csv(p) if os.path.exists(p) else []:
    children[r['id']] = (r['parent_id'], set(r['optionIds'].split('|')))
p = os.path.join(run, 'import', '05-variant-parents.csv')
parents_new = {r['id'] for r in read_csv(p)} if os.path.exists(p) else set()   # new and updated parents
p = os.path.join(run, 'import', 'delete-emptied-parents.json')
emptied = {r['id'] for r in json.load(open(p, encoding='utf-8'))} if os.path.exists(p) else set()

problems = collections.Counter()
examples = collections.defaultdict(list)
for pid in before.keys() - after.keys() - emptied:
    problems['product deleted'] += 1
    examples['product deleted'].append(before[pid]['number'])
for pid in after.keys() - before.keys() - parents_new:
    problems['product created'] += 1
    examples['product created'].append(after[pid]['number'])
for pid in before.keys() & after.keys() - parents_new:      # our own parents: name/children/categories change
    b, a = before[pid], after[pid]
    for col in COLS[2:]:
        if b[col] == a[col] or col == 'stock':       # stock: the Minimax sync may run in between
            continue
        if col == 'properties':
            got, lost = ids(a[col]) - ids(b[col]), ids(b[col]) - ids(a[col])
            if lost == removed.get(pid, set()) and got == added.get(pid, set()):
                continue
        if col == 'text' and (pid in seo_ids or pid in text_ids):
            continue
        if col == 'categories' and pid in cat_ids:
            continue
        if col in ('cover', 'media') and pid in cover_ids:
            continue
        if pid in children and col == 'parent' and a[col] == children[pid][0]:
            continue
        if pid in children and col == 'options' and children[pid][1] <= ids(a[col]):
            continue
        problems[f'{col} changed'] += 1
        if len(examples[f'{col} changed']) < 5:
            examples[f'{col} changed'].append(b['number'])
missing = [pid for pid, want in added.items() if pid in after and not want <= ids(after[pid]['properties'])]
if missing:
    problems['expected properties missing'] = len(missing)
for pid in emptied & after.keys():
    problems['emptied parent still there'] += 1
for pid in parents_new:
    if pid not in after:
        problems['variant parent missing'] += 1
    elif int(after[pid]['children'] or 0) < 2:
        problems['variant parent with < 2 children'] += 1
print(f'{len(before)} products before, {len(after)} after ({len(parents_new)} new variant parents planned)')
for k, v in problems.items():
    print(f'  PROBLEM {k}: {v}  e.g. {", ".join(examples.get(k, [])[:5])}')
print('OK: only the planned changes' if not problems else 'NOT OK')
sys.exit(1 if problems else 0)
