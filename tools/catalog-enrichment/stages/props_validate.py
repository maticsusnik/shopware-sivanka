#!/usr/bin/env python3
"""props_validate <run dir> : check the agents' property proposals (props/out/*.json).

Every proposal must survive:
  - evidence is a verbatim substring (whitespace/case-normalised) of the product's own text
  - the value's numbers / material stem / colour / brand occur in that evidence
  - the value matches the group's format or allowed list
  - no variants; parents only get groups that cannot differ per variant
  - single-value groups: an existing value wins; no duplicates
Status per proposal:
  auto      high confidence, all checks passed -> imported unless a decision rejects it
  review    checks passed but medium/low confidence -> imported only when approved
  rejected  a check failed (not reviewable)
Decisions (state/decisions.json, filled by `enrich.sh review`) override auto/review.
Writes props/review.csv (for people) and props/accepted.jsonl (for build).
"""
import collections
import json
import os
import re
import sys
import unicodedata

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'lib'))
from catalog import Catalog, decision_key, load_state, norm, squash, write_csv  # noqa: E402
from values import BRANDS, ENUM, FMT, FREE, MATERIAL_STEMS, MULTI, PARENT_OK, canonical, clean_value, num, numbers, unaccent  # noqa: E402,F401

run = sys.argv[1]
c = Catalog(run)
items = {x['id']: x for x in json.load(open(os.path.join(run, 'props', 'all.json'), encoding='utf-8'))}
decisions = load_state('decisions.json', {})

def check(item, p):
    g, v, ev = p['group'], p['value'], p.get('evidence') or ''
    hay = ' '.join(x or '' for x in (item['name'], item['short_description'], item['description']))
    if not ev or norm(ev) not in norm(hay):
        return 'evidence not found in product text'
    if g not in FMT and g not in ENUM and g not in FREE:
        # a group the shop doesn't have yet: allowed, but always goes to review (it becomes a filter)
        if not re.fullmatch(r'[A-ZČŠŽ][\wčšžČŠŽ ]{2,30}', g) or not 1 <= len(v) <= 40:
            return f'new group "{g}": bad group or value shape'
        if norm(v) not in norm(hay) and not any(n in ev for n in numbers(v)):
            return 'new group: value not in the product text'
        return 'NEW_GROUP'
    if g in FMT and not re.fullmatch(FMT[g], v):
        return f'value format ({FMT[g]})'
    if g in ENUM and v not in ENUM[g]:
        return 'value not in the allowed list'
    if g in FMT:
        evn = {num(n) for n in numbers(ev)}
        for n in numbers(v):
            if num(n) not in evn and not (g == 'Dolzina' and num(n) / 100 in evn):
                return f'number {n} not in evidence'
    if g == 'Material' and not any(st in squash(ev) for st in MATERIAL_STEMS[v]):
        return 'material word not in evidence'
    if g == 'Barva' and squash(v)[:3] not in squash(ev) and not (v == 'Staro zlato' and 'starozlat' in squash(ev)):
        return 'colour word not in evidence'
    if g == 'Blagovna znamka' and not any(len(t) >= 3 and unaccent(t) in unaccent(squash(ev))
                                          for t in [squash(v)] + [x.casefold() for x in v.split()]):
        return 'brand not in evidence'
    if g == 'Širina' and re.search(r'okrogl', item['name'] or '', re.I):
        return 'round elastic/cord: mm is a diameter, not a width'
    if item['kind'] == 'parent' and g not in PARENT_OK:
        return 'parent product: value may differ per variant'
    return ''


rows, accepted = [], []
stats = collections.Counter()
done = set()          # batch-*.json sorts before old-*.json (reused): a re-read product counts once, newest first
outdir = os.path.join(run, 'props', 'out')
for f in sorted(os.listdir(outdir)):
    for rec in json.load(open(os.path.join(outdir, f), encoding='utf-8')):
        item = items.get(rec.get('id'))
        if not item:
            stats['unknown product id'] += 1
            continue
        if item['id'] in done:
            continue
        done.add(item['id'])
        taken = {g: set(vs) for g, vs in c.existing(c.by_id[item['id']]).items()}
        for p in rec.get('properties') or []:
            p = dict(p)
            p['group'], p['value'] = canonical(p.get('group'), p.get('value'))
            g = p.get('group', '')
            p['value'] = v = clean_value(g, p.get('value', ''))
            why = check(item, p)
            new_group = why == 'NEW_GROUP'
            why = '' if new_group else why
            if not why and v in taken.get(g, set()):
                why = 'already assigned'
            elif not why and g not in MULTI and taken.get(g):
                why = f'product already has {g}: {", ".join(sorted(taken[g]))}'
            key = decision_key('prop', item['number'], g, v)
            d = decisions.get(key, {}).get('decision', '')
            status = 'rejected' if why else 'auto' if p.get('confidence') == 'high' and not new_group else 'review'
            if new_group and not why:
                why = f'new group "{g}" (becomes a storefront filter)'
            take = status == 'auto' and d != 'reject' or status == 'review' and d == 'approve'
            if take:
                gid, oid, new_opt, new_grp = c.resolve_option(g, v)
                taken.setdefault(g, set()).add(v)
                accepted.append(dict(product_id=item['id'], number=item['number'], group=g, value=v,
                                     group_id=gid, option_id=oid, new_option=new_opt, new_group=new_grp))
            stats[status + (' -> imported' if take else ' -> not imported')] += 1
            rows.append([key, d, status, 'yes' if take else 'no', item['number'], item['kind'], item['name'], g, v,
                         p.get('source'), p.get('evidence'), p.get('confidence'), why])

n = write_csv(os.path.join(run, 'props', 'review.csv'),
              ['key', 'decision', 'status', 'will_import', 'product_number', 'kind', 'name', 'group', 'value',
               'source', 'evidence', 'confidence', 'reason'],
              sorted(rows, key=lambda r: ({'review': 0, 'auto': 1, 'rejected': 2}[r[2]], r[7], r[4])))
with open(os.path.join(run, 'props', 'accepted.jsonl'), 'w', encoding='utf-8') as fh:
    for a in accepted:
        fh.write(json.dumps(a, ensure_ascii=False) + '\n')
per_group = collections.Counter(a['group'] for a in accepted)
print(json.dumps(dict(stats=stats, accepted_per_group=per_group, review_rows=n), ensure_ascii=False, indent=1))
