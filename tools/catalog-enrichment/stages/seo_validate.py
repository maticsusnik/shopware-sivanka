#!/usr/bin/env python3
"""seo_validate <run dir> : check the agents' SEO texts (seo/out/*.json) -> seo/review.csv, seo/accepted.jsonl.

Checks: title <= 60 chars and ends with " | Sivanka"; description 100-160 chars; 3-10 keywords
ending with the product number; product number not in title/description; no shouting
(ALL-CAPS words other than codes/units/known abbreviations), no "!", no banned marketing words;
numbers in title/description must occur in the product's own data (no invented sizes).
Status: auto (all checks, high confidence) | review (low confidence) | rejected (a check failed).
A decision (key seo|<number>|meta|-) overrides auto/review, as for properties.
"""
import collections
import json
import os
import re
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'lib'))
from catalog import decision_key, load_state, norm, write_csv  # noqa: E402

run = sys.argv[1]
items = {x['id']: x for x in json.load(open(os.path.join(run, 'seo', 'all.json'), encoding='utf-8'))}
decisions = load_state('decisions.json', {})
SUFFIX = ' | Sivanka'
BANNED = re.compile(r'\b(najboljš\w*|vrhunsk\w*|top|akcij\w*|poceni|brezplačn\w*|garancij\w*)\b', re.I)
OK_CAPS = {'PVC', 'PES', 'PA', 'PL', 'DMC', 'DIY', 'XL', 'XXL', 'KAI', 'YKK', 'II', 'III', 'LED', 'EU', 'UV', 'ABS'}


def problems(item, r):
    t, d, k = (r.get('meta_title') or '').strip(), (r.get('meta_description') or '').strip(), (r.get('keywords') or '').strip()
    out = []
    if not t.endswith(SUFFIX):
        out.append('title must end with " | Sivanka"')
    if len(t) > 60:
        out.append(f'title {len(t)} > 60 chars')
    if not 100 <= len(d) <= 160:
        out.append(f'description {len(d)} chars (100-160)')
    kws = [x.strip() for x in k.split(',') if x.strip()]
    if not 3 <= len(kws) <= 10:
        out.append(f'{len(kws)} keywords (3-10)')
    if kws and kws[-1] != item['number']:
        out.append('keywords must end with the product number')
    num = re.escape(item['number'])
    if re.search(r'(?<![\w.])' + num + r'(?![\w])', t + ' ' + d) and len(item['number']) >= 4:
        out.append('product number in title/description')
    if '!' in t + d:
        out.append('exclamation mark')
    if BANNED.search(t + ' ' + d):
        out.append(f'marketing word "{BANNED.search(t + " " + d).group(0)}"')
    caps = [w for w in re.findall(r'\b[A-ZČŠŽ]{3,}\b', t.replace(SUFFIX, '') + ' ' + d) if w not in OK_CAPS]
    if caps:
        out.append(f'shouting: {" ".join(caps[:3])}')
    src = ' '.join(str(x or '') for x in (item['name'], item['short_description'], item['description'],
                                          item['category'], ' '.join(item['properties'])))
    have = {n.replace(',', '.').lstrip('0') or '0' for n in re.findall(r'\d+(?:[.,]\d+)?', src)}
    for n in re.findall(r'\d+(?:[.,]\d+)?', t + ' ' + d):
        if (n.replace(',', '.').lstrip('0') or '0') not in have:
            out.append(f'number {n} not in the product data')
            break
    return out


rows, accepted = [], []
stats = collections.Counter()
seen = set()
outdir = os.path.join(run, 'seo', 'out')
for f in sorted(os.listdir(outdir)):
    for r in json.load(open(os.path.join(outdir, f), encoding='utf-8')):
        item = items.get(r.get('id'))
        if not item or item['id'] in seen:
            stats['unknown or duplicate id'] += 1
            continue
        seen.add(item['id'])
        why = problems(item, r)
        key = decision_key('seo', item['number'], 'meta', '-')
        d = decisions.get(key, {}).get('decision', '')
        status = 'rejected' if why else 'auto' if r.get('confidence') == 'high' else 'review'
        take = status == 'auto' and d != 'reject' or status == 'review' and d == 'approve'
        if take:
            accepted.append(dict(product_id=item['id'], number=item['number'], meta_title=r['meta_title'].strip(),
                                 meta_description=r['meta_description'].strip(), keywords=r['keywords'].strip()))
        stats[status + (' -> imported' if take else ' -> not imported')] += 1
        cur = item['current']
        rows.append([key, d, status, 'yes' if take else 'no', item['number'], item['name'],
                     cur['meta_title'], r.get('meta_title'), cur['meta_description'], r.get('meta_description'),
                     cur['keywords'], r.get('keywords'), r.get('confidence'), '; '.join(why) or r.get('note', '')])
stats['products without agent output'] = len(items.keys() - seen)

write_csv(os.path.join(run, 'seo', 'review.csv'),
          ['key', 'decision', 'status', 'will_import', 'product_number', 'name', 'old_meta_title', 'new_meta_title',
           'old_meta_description', 'new_meta_description', 'old_keywords', 'new_keywords', 'confidence', 'reason'],
          sorted(rows, key=lambda x: ({'rejected': 0, 'review': 1, 'auto': 2}[x[2]], x[4])))
with open(os.path.join(run, 'seo', 'accepted.jsonl'), 'w', encoding='utf-8') as fh:
    for a in accepted:
        fh.write(json.dumps(a, ensure_ascii=False) + '\n')
print(json.dumps(stats, ensure_ascii=False, indent=1))
