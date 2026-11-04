#!/usr/bin/env python3
"""reuse <old run dir> <new run dir> : carry the agent results of an earlier run into a new run.

Product ids survive a fresh prod dump, so agent output (props, seo, variants, categories), the fixes and
the collapse plan can be reused. Run AFTER `extract` and the four *_prepare stages in the new run (they
write a fresh all.json from the new data; prepare with --all / --scope all so every product is listed).
This copies the old out/*.json (+ variants clusters.json), fixes/ and gsc/, then reports which products are
new or whose text changed since the old run: only those need agents. The validators then re-check every
old proposal against the NEW data (a proposal whose evidence is gone is rejected).
"""
import json
import os
import shutil
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'lib'))
from catalog import Catalog, content_hash  # noqa: E402

old, new = sys.argv[1], sys.argv[2]
co, cn = Catalog(old), Catalog(new)

for stage in ('props', 'seo', 'variants', 'categories'):
    src, dst = os.path.join(old, stage, 'out'), os.path.join(new, stage, 'out')
    if not os.path.isdir(src):
        continue
    os.makedirs(dst, exist_ok=True)
    for f in os.listdir(src):
        shutil.copy2(os.path.join(src, f), os.path.join(dst, 'old-' + f))
    print(f'{stage}: copied {len(os.listdir(src))} agent files')
shutil.copy2(os.path.join(old, 'variants', 'clusters.json'), os.path.join(new, 'variants', 'clusters.json'))
for d in ('fixes', 'gsc'):
    if os.path.isdir(os.path.join(old, d)):
        shutil.copytree(os.path.join(old, d), os.path.join(new, d), dirs_exist_ok=True)

# collapse plan: still valid only where those old hand-made families still exist
plan_path = os.path.join(old, 'collapse-plan.json')
if os.path.exists(plan_path):
    plan = json.load(open(plan_path, encoding='utf-8'))
    nums = {p['number']: p for p in cn.products}
    still = [n for n in plan['split'] if nums.get(n, {}).get('children')]
    if still:
        keep = {'split': still, 'delete': [n for n in plan['delete'] if n in nums]}
        json.dump(keep, open(os.path.join(new, 'collapse-plan.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(f'collapse plan: {len(still)} of {len(plan["split"])} old parents still have variants'
          + ('' if still else ' (nothing to collapse)'))

seen = {p['id'] for p in co.products}
changed = [p for p in cn.products if p['id'] in seen and content_hash(p) != content_hash(co.by_id[p['id']])]
added = [p for p in cn.products if p['id'] not in seen]
removed = seen - {p['id'] for p in cn.products}
json.dump({'changed': [p['number'] for p in changed], 'new': [p['number'] for p in added]},
          open(os.path.join(new, 'reuse-report.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print(f'products: {len(changed)} with changed text, {len(added)} new, {len(removed)} gone since {os.path.basename(old)}')
print('new/changed products need agents (see reuse-report.json); the rest reuses the old results')
