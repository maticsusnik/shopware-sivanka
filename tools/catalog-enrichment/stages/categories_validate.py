#!/usr/bin/env python3
"""categories_validate <run dir> : check categories/out/*.json -> categories/review.csv, categories/accepted.jsonl.

A proposal needs 1-2 ids from the product tree (pages below Izdelki) and a reason. Status: auto (high),
review (medium; imported only when approved, key cat|<number>|categories|<ids>), rejected (bad id / none).
"""
import collections
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'lib'))
from catalog import Catalog, decision_key, load_state, write_csv  # noqa: E402

run = sys.argv[1]
c = Catalog(run)
items = {x['id']: x for x in json.load(open(os.path.join(run, 'categories', 'all.json'), encoding='utf-8'))}
tree = {}
for x in c.categories:
    path = c.cat_path(x)
    if x['type'] == 'page' and any(y['name'] == 'Izdelki' for y in path[:-1]):
        tree[x['id']] = ' > '.join(y['name'] for y in path if y['name'] != 'Izdelki' and y['level'] > 1)
decisions = load_state('decisions.json', {})
rows, accepted, stats = [], [], collections.Counter()
for f in sorted(os.listdir(os.path.join(run, 'categories', 'out'))):
    for r in json.load(open(os.path.join(run, 'categories', 'out', f), encoding='utf-8')):
        item = items.get(r.get('id'))
        if not item:
            stats['unknown id'] += 1
            continue
        if any(x[4] == item['number'] for x in rows):
            continue
        ids = [i for i in r.get('categories') or [] if i]
        why = []
        if not ids:
            why.append('no category proposed: ' + (r.get('why') or ''))
        if len(ids) > 2:
            why.append('more than 2 categories')
        if any(i not in tree for i in ids):
            why.append('unknown category id')
        key = decision_key('cat', item['number'], 'categories', ','.join(sorted(ids)))
        d = decisions.get(key, {}).get('decision', '')
        status = 'rejected' if why else 'auto' if r.get('confidence') == 'high' else 'review'
        take = status == 'auto' and d != 'reject' or status == 'review' and d == 'approve'
        if take:
            accepted.append(dict(product_id=item['id'], number=item['number'], categories=ids))
        stats[status + (' -> imported' if take else ' -> not imported')] += 1
        rows.append([key, d, status, 'yes' if take else 'no', item['number'], item['name'], 'yes' if item['active'] else 'no',
                     ' | '.join(tree.get(i, i) for i in ids), r.get('confidence'), '; '.join(why) or r.get('why', '')])
stats['products without agent output'] = len(items) - len({r[4] for r in rows})
write_csv(os.path.join(run, 'categories', 'review.csv'),
          ['key', 'decision', 'status', 'will_import', 'product_number', 'name', 'active', 'categories', 'confidence', 'reason'],
          sorted(rows, key=lambda x: ({'review': 0, 'rejected': 1, 'auto': 2}[x[2]], x[7], x[4])))
with open(os.path.join(run, 'categories', 'accepted.jsonl'), 'w', encoding='utf-8') as fh:
    for a in accepted:
        fh.write(json.dumps(a, ensure_ascii=False) + '\n')
print(json.dumps(dict(stats=stats, per_category=collections.Counter(tree[i] for a in accepted for i in a['categories']).most_common(15)),
                 ensure_ascii=False, indent=1))
