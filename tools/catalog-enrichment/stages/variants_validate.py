#!/usr/bin/env python3
"""variants_validate <run dir> : check the agents' variant families (variants/out/*.json).

Checks per family: >= 2 members; every member is an active standalone product of the cluster and
in no other family; every member has a value for every group; no duplicate combinations; each
value is readable from the member's own name/description; `main` is a member.
Every family needs a person's approval (decision key variant|<parent number>|family|<member numbers>):
a family is never built on confidence alone. Writes variants/review.csv and variants/accepted.json.
"""
import collections
import json
import os
import re
import sys
import unicodedata

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'lib'))
from catalog import Catalog, decision_key, load_state, squash, stable_id, strip_html, write_csv  # noqa: E402
from values import FMT, ENUM, canonical, clean_value  # noqa: E402

run = sys.argv[1]
c = Catalog(run)
clusters = {x['cluster']: x for x in json.load(open(os.path.join(run, 'variants', 'clusters.json'), encoding='utf-8'))}
decisions = load_state('decisions.json', {})
used_numbers = set(p['number'] for p in c.products)


def ascii_slug(s):
    s = unicodedata.normalize('NFD', s)
    s = ''.join(ch for ch in s if unicodedata.category(ch) != 'Mn')
    return re.sub(r'[^A-Z0-9]+', '-', s.upper()).strip('-')[:40]


def fmt_value(group, v):
    v = re.sub(r'\s+', ' ', str(v or '').strip())
    if group in FMT or group in ENUM:
        return clean_value(group, v)
    if re.fullmatch(r'\d+(?:[.,]\d+)?\s?(mm|cm|m|g)', v, re.I):
        v = re.sub(r'\s', '', v.replace('.', ',')).lower()
    return v[:1].upper() + v[1:] if v and not re.match(r'\d', v) and group in ('Barva', 'Oblika', 'Material') else v


def readable(p, value, evidence):
    hay = squash(' '.join(x or '' for x in (p['name'], strip_html(p['short_description']), strip_html(p['description']))))
    # one fragment per group is allowed, joined by " / ", "|" or ";"
    if evidence and any(squash(e) not in hay for e in re.split(r'\s+/\s+|\||;', evidence) if e.strip()):
        return False
    nums = re.findall(r'\d+(?:[.,]\d+)?', value)
    if nums:
        return all(n.replace('.', ',') in hay.replace('.', ',') for n in nums)
    v = squash(value)
    if v == 'prozorna' and ('transparent' in hay or 'prozor' in hay):
        return True
    return v[:4] in hay or v[:3] in hay           # colour words are inflected: zlat/zlata/zlato


rows, accepted, taken = [], [], {}
parent_numbers = set()
stats = collections.Counter()
for f in sorted(os.listdir(os.path.join(run, 'variants', 'out'))):
    for rec in json.load(open(os.path.join(run, 'variants', 'out', f), encoding='utf-8')):
        cl = clusters.get(rec.get('cluster'))
        if not cl:
            stats['unknown cluster'] += 1
            continue
        allowed = {x['id'] for x in cl['products']}
        for fam in rec.get('families') or []:
            why = []
            members = fam.get('members') or []
            # one group name per concept: rename a group for the whole family (e.g. Schmetz Nm sizes)
            raw_groups = [g.strip() for g in fam.get('groups') or [] if g.strip()]
            rename = {}
            for g in raw_groups:
                vals = [canonical(g, (m.get('options') or {}).get(g)) for m in members]
                if vals and len({x[0] for x in vals}) == 1 and vals[0][0] != g:
                    rename[g] = vals[0][0]
            for m in members:
                m['options'] = {rename.get(g, g): canonical(g, v)[1] if g in rename else v
                                for g, v in (m.get('options') or {}).items()}
            groups = [rename.get(g, g) for g in raw_groups]
            if len(members) < 2:
                why.append('fewer than 2 members')
            if not 1 <= len(groups) <= 2:
                why.append(f'{len(groups)} groups (1-2)')
            combos = set()
            for m in members:
                p = c.by_id.get(m.get('id'))
                if not p or m['id'] not in allowed:
                    why.append(f'member {m.get("id")} not in the cluster')
                    continue
                if m['id'] in taken:
                    why.append(f'{p["number"]} is already in {taken[m["id"]]}')
                opts = {g: fmt_value(g, (m.get('options') or {}).get(g)) for g in groups}
                m['options'] = opts
                if any(not v for v in opts.values()):
                    why.append(f'{p["number"]}: missing value')
                elif not all(readable(p, v, m.get('evidence')) for v in opts.values()):
                    why.append(f'{p["number"]}: value {"/".join(opts.values())} not in its own text')
                combo = tuple(opts[g] for g in groups)
                if combo in combos:
                    why.append(f'duplicate combination {"/".join(combo)}')
                combos.add(combo)
            ids = [m.get('id') for m in members]
            if fam.get('main') not in ids:
                why.append('main is not a member')
            elif any(c.by_id[i]['active'] for i in ids if i in c.by_id) and not c.by_id[fam['main']]['active']:
                why.append('main is inactive although the family has active members')
            name = (fam.get('parent_name') or '').strip()
            if not name:
                why.append('no parent name')
            base = 'parent_' + ascii_slug(name)
            number, k = base, 2
            while number in parent_numbers or number in used_numbers:
                number, k = f'{base}-{k}', k + 1
            parent_numbers.add(number)
            nums = sorted(c.by_id[i]['number'] for i in ids if i in c.by_id)
            key = decision_key('variant', number, 'family', ','.join(nums))
            d = decisions.get(key, {}).get('decision', '')
            prices = [c.by_id[i]['price_gross'][0] for i in ids if i in c.by_id and c.by_id[i]['price_gross']]
            warn = []
            if prices and min(prices) > 0 and max(prices) / min(prices) > 4:
                warn.append(f'check: price spread {min(prices)}-{max(prices)} €')
            status = 'rejected' if why else 'review'
            take = status == 'review' and d == 'approve'
            if not why:
                for i in ids:
                    taken[i] = number
            if take:
                main = c.by_id[fam['main']]
                accepted.append(dict(
                    parent_id=stable_id('variant-parent', number), parent_number=number, parent_name=name,
                    groups=groups, main_id=main['id'], tax_id=main['tax_id'],
                    active=any(c.by_id[i]['active'] for i in ids),
                    price_gross=main['price_gross'][0] if main['price_gross'] else 0,
                    price_net=main['price_net'][0] if main['price_net'] else 0,
                    categories=sorted({cid for i in ids for cid in c.by_id[i]['categories']}),
                    members=[dict(id=m['id'], number=c.by_id[m['id']]['number'], options=m['options']) for m in members]))
            stats[status + (' -> built' if take else ' -> not built')] += 1
            rows.append([key, d, status, 'yes' if take else 'no', number, name, ', '.join(groups), len(members),
                         ' | '.join(f'{c.by_id[m["id"]]["number"]} {c.by_id[m["id"]]["name"]} = {"/".join(m["options"].values())}'
                                    for m in members if m.get('id') in c.by_id),
                         f'{min(prices)}-{max(prices)}' if prices else '', fam.get('confidence'),
                         '; '.join(why + warn + ([fam['note']] if fam.get('note') else []))])
        for lo in rec.get('left_out') or []:
            stats['left out by agent'] += 1

write_csv(os.path.join(run, 'variants', 'review.csv'),
          ['key', 'decision', 'status', 'will_build', 'parent_number', 'parent_name', 'groups', 'members_count',
           'members (number name = option)', 'price_range_eur', 'confidence', 'reason'],
          sorted(rows, key=lambda r: ({'review': 0, 'rejected': 1}[r[2]], r[4])))
json.dump(accepted, open(os.path.join(run, 'variants', 'accepted.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print(json.dumps(stats, ensure_ascii=False, indent=1))
