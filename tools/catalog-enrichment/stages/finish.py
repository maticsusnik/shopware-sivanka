#!/usr/bin/env python3
"""finish <run dir> : mark the products read in this run as done (state/content-hashes.json).

Run it once the run's files are imported on prod. The next `props` run then reads only products
whose name or description changed since, plus new ones.
"""
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'lib'))
from catalog import Catalog, content_hash, load_state, save_state  # noqa: E402

run = sys.argv[1]
c = Catalog(run)
read = json.load(open(os.path.join(run, 'props', 'all.json'), encoding='utf-8'))
hashes = load_state('content-hashes.json', {})
for item in read:
    hashes[item['number']] = content_hash(c.by_id[item['id']])
save_state('content-hashes.json', hashes)
open(os.path.join(run, 'FINISHED'), 'w').write('imported on prod\n')
print(f'{len(read)} products marked as read, {len(hashes)} in total')
