#!/usr/bin/env python3
"""variants_numbers <run dir> : family members found by product NUMBER pattern (no agents).

Rule (Matic, 2026-11-05): a product joins a family when its number equals a member's number except for the
option value (lib/numbercodes.py), even if the value is in neither name nor description, active or inactive.
1. Existing families: standalone products whose number fits a member's pattern -> extend (keep_parent_id).
2. New families: standalone products whose numbers differ only in a colour word or a `B.<code>` shade, and
   whose names share the same stem -> one family `Barva`.
Writes variants/out/batch-numbers.json + its clusters (n-…) into variants/clusters.json, and
variants/numbers-report.csv (added / duplicate / skipped with the reason). The normal validator then checks it.
"""
import collections
import json
import os
import re
import statistics
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(os.path.dirname(HERE), 'lib'))
from catalog import Catalog, squash, strip_html, write_csv  # noqa: E402
from numbercodes import _colour_of, value_from_number  # noqa: E402
from values import BARVA  # noqa: E402

run = sys.argv[1]
c = Catalog(run)
sys.argv = [sys.argv[0], run]
stem = None
src = open(os.path.join(HERE, 'variants_prepare.py'), encoding='utf-8').read()
ns = {'re': re}
exec(src[src.index('COLOURS = ('):src.index('def family_of')], ns)   # reuse the clustering stem
stem = ns['stem']

fams = collections.defaultdict(list)
for p in c.products:
    if p['kind'] == 'variant':
        fams[p['parent']].append(p)


def opts(p):
    return {c.opt[o]['group']: c.opt[o]['name'] for o in p['options'] if o in c.opt}


def price(p):
    src_p = p if p['price_gross'] else c.by_id.get(p['parent'] or '', p)
    return (src_p['price_gross'] or [0])[0] or 0


def states_other_colour(p, colour):
    text = squash(' '.join(x or '' for x in (p['name'], strip_html(p['short_description']))))
    for b in BARVA:
        if b != colour and b.casefold()[:4] in text and (colour or '').casefold()[:4] not in text:
            return True
    return False


def first(name):
    w = stem(name or '').split()
    return w[0] if w else ''


standalone = [p for p in c.products if p['kind'] == 'simple' and p['name']]
report, records, clusters = [], [], []
taken, dupes = set(), set()
# shade codes each product line (first stem word) already has in a family: a standalone with one of them is a duplicate
line_shades = collections.defaultdict(dict)
for pid_, ms in fams.items():
    for m in ms:
        for o in m['options']:
            if o in c.opt and c.opt[o]['name'].startswith('b. '):
                line_shades[(stem(m['name'] or '').split() or [''])[0]][c.opt[o]['name']] = m['number']

# 1) extend existing families -------------------------------------------------------------------------
for pid, members in sorted(fams.items(), key=lambda x: c.by_id[x[0]]['number']):
    parent = c.by_id[pid]
    mopts = {m['id']: opts(m) for m in members}
    groups = sorted({g for o in mopts.values() for g in o})
    if not groups or any(len(o) != len(groups) for o in mopts.values()):
        continue
    combos = {tuple(o[g] for g in groups): m for m, o in ((m, mopts[m['id']]) for m in members)}
    prices = [price(m) for m in members if price(m)]
    med = statistics.median(prices) if prices else 0
    found = {}
    first_words = {first(m['name']) for m in members} | {first(parent['name'])}
    first_words.discard('')
    for cand in standalone:
        if cand['id'] in taken or first(cand['name']) not in first_words:
            continue
        votes = collections.Counter()
        for m in members:
            for g in groups:
                v = value_from_number(cand['number'], m['number'], mopts[m['id']][g])
                if v:
                    new = dict(mopts[m['id']]); new[g] = v
                    votes[(tuple(new[x] for x in groups), m['number'])] += 1
        if not votes:
            continue
        combo_set = {k[0] for k in votes}
        if len(combo_set) > 1:
            report.append([cand['number'], cand['name'], parent['number'], 'skipped', 'number fits two different values'])
            continue
        combo, ref = next(iter(votes))
        if combo in combos:
            report.append([cand['number'], cand['name'], parent['number'], 'duplicate', f'same as {combos[combo]["number"]}'])
            dupes.add(cand['id'])
            continue
        p_ = price(cand)
        if med and p_ and not (med / 3 <= p_ <= med * 3):
            report.append([cand['number'], cand['name'], parent['number'], 'skipped', f'price {p_} vs family {med}'])
            continue
        if 'Barva' in groups and states_other_colour(cand, combo[groups.index('Barva')]):
            report.append([cand['number'], cand['name'], parent['number'], 'skipped', 'name states another colour'])
            continue
        if combo in found:
            report.append([cand['number'], cand['name'], parent['number'], 'duplicate', f'same as {found[combo][0]["number"]}'])
            dupes.add(cand['id'])
            continue
        found[combo] = (cand, ref)
    if not found:
        continue
    for combo, (cand, ref) in found.items():
        taken.add(cand['id'])
        report.append([cand['number'], cand['name'], parent['number'], 'added',
                       ', '.join(f'{g} {v}' for g, v in zip(groups, combo)) + f' (number pattern of {ref})'])
    cl = f'n-{parent["number"]}'
    new_members = [dict(id=cand['id'], options=dict(zip(groups, combo)), evidence=f'number: {ref}')
                   for combo, (cand, ref) in found.items()]
    cur = [dict(id=m['id'], options=mopts[m['id']], evidence='') for m in members]
    actives = [m for m in members + [x[0] for x in found.values()] if m['active']]
    main = max(actives or members, key=lambda m: (m['stock'] or 0))
    clusters.append({'cluster': cl, 'stem': parent['name'], 'products': [{'id': x['id'], 'number': x['number']}
                                                                         for x in members + [v[0] for v in found.values()]]})
    records.append({'cluster': cl, 'families': [{
        'keep_parent_id': pid, 'parent_name': parent['name'], 'groups': groups, 'main': main['id'],
        'confidence': 'medium', 'members': cur + new_members, 'note': 'members added by product number pattern'}]})

# 2) new families from standalones: numbers differ only in a colour word or a B.<code> shade ---------------
by_tpl = collections.defaultdict(list)
for p in standalone:
    if p['id'] in taken or p['id'] in dupes:
        continue
    num = p['number']
    m = re.search(r'(?<![A-Za-z])B\.\s?(\d+[A-Z]?)\b', num)
    if m:
        by_tpl[('shade', num[:m.start()] + '{}' + num[m.end():], stem(p['name']))].append((p, 'b. ' + m.group(1).lower()))
        continue
    for t in re.finditer(r'[A-Za-zČŠŽčšž]+(?:\.[A-Za-zČŠŽčšž]+)?', num):
        col = _colour_of(t.group(0))
        if col and len(t.group(0)) >= 2:
            by_tpl[('colour', num[:t.start()] + '{}' + num[t.end():], stem(p['name']))].append((p, col))
            break
for (kind, tpl, st), items in sorted(by_tpl.items()):
    if len(items) < 2 or len(st) < 4:
        continue
    seen, members = {}, []
    for p, v in sorted(items, key=lambda x: (not x[0]['active'], -(x[0]['stock'] or 0))):
        other = line_shades.get(first(p['name']), {}).get(v)
        if kind == 'shade' and other:
            report.append([p['number'], p['name'], '(new)', 'duplicate', f'same shade as family member {other}'])
            continue
        if v in seen:
            report.append([p['number'], p['name'], '(new)', 'duplicate', f'same as {seen[v]["number"]}'])
            continue
        if kind == 'colour' and states_other_colour(p, v):
            report.append([p['number'], p['name'], '(new)', 'skipped', 'name states another colour'])
            continue
        seen[v] = p
        members.append((p, v))
    if len(members) < 2:
        continue
    prices = [price(p) for p, _ in members if price(p)]
    if prices and max(prices) > 3 * min(prices):
        for p, _ in members:
            report.append([p['number'], p['name'], '(new)', 'skipped', f'price spread {min(prices)}-{max(prices)}'])
        continue
    cl = 'n-new-' + re.sub(r'[^A-Za-z0-9]+', '-', tpl)[:40]
    actives = [p for p, _ in members if p['active']]
    main = max(actives or [p for p, _ in members], key=lambda p: (p['stock'] or 0))
    name = members[0][0]['name']
    name = re.sub(r'\s+', ' ', re.sub(r'(?i)\bB\.\s?\d+[A-Z]?\b', '', name)).strip() if kind == 'shade' else name
    if kind == 'colour':
        name = ' '.join(w for w in name.split() if not _colour_of(w)) or name
    for p, v in members:
        report.append([p['number'], p['name'], '(new)', 'added', f'Barva {v} (number pattern {tpl})'])
    clusters.append({'cluster': cl, 'stem': st, 'products': [{'id': p['id'], 'number': p['number']} for p, _ in members]})
    records.append({'cluster': cl, 'families': [{
        'parent_name': name[:1].upper() + name[1:].lower(), 'groups': ['Barva'], 'main': main['id'], 'confidence': 'medium',
        'members': [dict(id=p['id'], options={'Barva': v}, evidence=f'number: {tpl}') for p, v in members],
        'note': 'new family by product number pattern'}]})

out = os.path.join(run, 'variants', 'out')
os.makedirs(out, exist_ok=True)
json.dump(records, open(os.path.join(out, 'batch-numbers.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
cpath = os.path.join(run, 'variants', 'clusters.json')
existing = [x for x in (json.load(open(cpath, encoding='utf-8')) if os.path.exists(cpath) else []) if not x['cluster'].startswith('n-')]
json.dump(existing + clusters, open(cpath, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
write_csv(os.path.join(run, 'variants', 'numbers-report.csv'), ['number', 'name', 'family', 'result', 'detail'], report)
cnt = collections.Counter(r[3] for r in report)
print(f'{len(records)} families ({sum(1 for r in records if "keep_parent_id" in r["families"][0])} extended, '
      f'{sum(1 for r in records if "keep_parent_id" not in r["families"][0])} new); products: {dict(cnt)}')
