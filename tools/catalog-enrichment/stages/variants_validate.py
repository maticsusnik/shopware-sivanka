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
from numbercodes import _colour_of, value_from_number  # noqa: E402

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
    # common abbreviations / foreign spellings in Minimax names: ST.ZLATO, T.MODER, SV.SIV, beige, beš
    raw = ' '.join(x or '' for x in (p['name'], strip_html(p['short_description']), strip_html(p['description']))).casefold()
    raw = re.sub(r'(?<![a-zčšž])st\.?\s?(?=zlat)', 'staro ', raw)
    raw = re.sub(r'(?<![a-zčšž])t\.\s?(?=[a-zčšž])', 'temno ', raw)
    raw = re.sub(r'(?<![a-zčšž])sv\.\s?(?=[a-zčšž])', 'svetlo ', raw)
    raw = raw.replace('beige', 'bež').replace('beš', 'bež').replace('transparent', 'prozor')
    hay2 = squash(raw)
    for h in (hay, hay2):
        if v[:4] in h or v[:3] in h:              # colour words are inflected: zlat/zlata/zlato
            return True
    # two-word colours: every word (inflected) must be there, e.g. Staro zlato, Temno modra, Svetlo siva
    words = [w for w in re.split(r'\s+', value.casefold()) if w]
    return len(words) > 1 and all(w[:4] in hay2 or w[:3] in hay2 for w in words)


def number_ok(p, opts, ref, members):
    """Value read from the product number (lib/numbercodes.py): `ref` is a member's number (its pattern minus the
    value equals this number minus its value), or a template with {} for new families."""
    if '{}' in ref:
        rx = '^' + re.escape(ref).replace(r'\{\}', r'(.+)') + '$'
        mm = re.match(rx, p['number'])
        if not mm or len(opts) != 1:
            return False
        tok, v = mm.group(1), next(iter(opts.values()))
        return (_colour_of(tok) == v) or (v.startswith('b. ') and squash(v[3:]) == squash(tok.replace('B.', '')))
    for m in members:
        q = c.by_id.get(m.get('id'))
        if not q or q['number'] != ref:
            continue
        qo = m.get('options') or {}
        for g, v in opts.items():
            if g in qo and value_from_number(p['number'], ref, qo[g]) == v and all(
                    opts[x] == qo.get(x) for x in opts if x != g):
                return True
    return False


rows, accepted, taken = [], [], {}
parent_numbers = set()
stats = collections.Counter()
# existing families: parent id -> {child id: {group: value}}
current = collections.defaultdict(dict)
for p in c.products:
    if p['kind'] == 'variant':
        current[p['parent']][p['id']] = {c.opt[o]['group']: c.opt[o]['name'] for o in p['options'] if o in c.opt}
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
            ids = [m.get('id') for m in members]
            keep = fam.get('keep_parent_id') or None
            # existing families this family touches: kept one + any merged into it
            touched = sorted({c.by_id[i]['parent'] for i in ids if i in c.by_id and c.by_id[i]['kind'] == 'variant'})
            if keep and keep not in current:
                why.append('keep_parent_id is not an existing parent')
            if touched and keep not in touched:
                why.append('members are variants of an existing family but keep_parent_id does not name it')
            for pid in touched:
                missing = [c.by_id[x]['number'] for x in current[pid] if x not in ids]
                if missing:
                    why.append(f'drops current variants {", ".join(missing[:5])}')
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
                if p['kind'] == 'variant':
                    # a current variant keeps its values: same value for every group it already has
                    for g, v in current[p['parent']].get(p['id'], {}).items():
                        if g not in groups:
                            why.append(f'{p["number"]}: drops its group {g}')
                        elif squash(opts.get(g) or '') != squash(v):
                            why.append(f'{p["number"]}: {g} {v} -> {opts.get(g)} (current values must stay)')
                        else:
                            opts[g] = v
                m['options'] = opts
                if any(not v for v in opts.values()):
                    why.append(f'{p["number"]}: missing value')
                elif str(m.get('evidence') or '').startswith('number:'):
                    if not number_ok(p, opts, m['evidence'][7:].strip(), members):
                        why.append(f'{p["number"]}: value {"/".join(opts.values())} not in its number pattern')
                elif not all(readable(p, v, m.get('evidence')) or
                             (p['kind'] == 'variant' and current[p['parent']].get(p['id'], {}).get(g) == v)
                             for g, v in opts.items()):
                    why.append(f'{p["number"]}: value {"/".join(opts.values())} not in its own text')
                combo = tuple(opts[g] for g in groups)
                if combo in combos:
                    why.append(f'duplicate combination {"/".join(combo)}')
                combos.add(combo)
            if fam.get('main') not in ids:
                why.append('main is not a member')
            elif any(c.by_id[i]['active'] for i in ids if i in c.by_id) and not c.by_id[fam['main']]['active']:
                why.append('main is inactive although the family has active members')
            name = (fam.get('parent_name') or '').strip()
            if not name:
                why.append('no parent name')
            if keep and keep in c.by_id:
                number = c.by_id[keep]['number']
                parent_id = keep
            else:
                base = 'parent_' + ascii_slug(name)
                number, k = base, 2
                while number in parent_numbers or number in used_numbers:
                    number, k = f'{base}-{k}', k + 1
                parent_id = stable_id('variant-parent', number)
            parent_numbers.add(number)
            # unchanged existing family (same members, same groups, same values): nothing to do
            if keep and not why and set(ids) == set(current.get(keep, {})) and all(
                    set(current[keep][m['id']].items()) == set(m['options'].items()) for m in members):
                stats['unchanged existing family'] += 1
                for i in ids:
                    taken[i] = number
                continue
            nums = sorted(c.by_id[i]['number'] for i in ids if i in c.by_id)
            key = decision_key('variant', number, 'family', ','.join(nums))
            d = decisions.get(key, {}).get('decision', '')
            prices = [c.by_id[i]['price_gross'][0] for i in ids if i in c.by_id and c.by_id[i]['price_gross']]
            warn = []
            if prices and min(prices) > 0 and max(prices) / min(prices) > 4:
                warn.append(f'check: price spread {min(prices)}-{max(prices)} €')
            merged = [pid for pid in touched if pid != keep]
            if merged:
                warn.append('merges ' + ', '.join(c.by_id[pid]['number'] for pid in merged) + ' into it (emptied parents get deleted)')
            new_members = [c.by_id[i]['number'] for i in ids if i in c.by_id and c.by_id[i]['kind'] != 'variant']
            status = 'rejected' if why else 'review'
            take = status == 'review' and d == 'approve'
            if not why:
                for i in ids:
                    taken[i] = number
            if take:
                main = c.by_id[fam['main']]
                price_src = main if main['price_gross'] else c.by_id.get(main['parent'] or '', main)
                accepted.append(dict(
                    parent_id=parent_id, parent_number=number, parent_name=name, existing=bool(keep),
                    merged_parents=merged, groups=groups, main_id=main['id'], tax_id=main['tax_id'] or price_src.get('tax_id'),
                    active=any(c.by_id[i]['active'] for i in ids),
                    price_gross=price_src['price_gross'][0] if price_src['price_gross'] else 0,
                    price_net=price_src['price_net'][0] if price_src['price_net'] else 0,
                    categories=sorted({cid for i in ids for cid in c.by_id[i]['categories']}),
                    members=[dict(id=m['id'], number=c.by_id[m['id']]['number'], options=m['options'],
                                  new=c.by_id[m['id']]['kind'] != 'variant' or c.by_id[m['id']]['parent'] != parent_id)
                             for m in members]))
            stats[status + (' -> built' if take else ' -> not built') + (' (existing)' if keep else ' (new)')] += 1
            rows.append([key, d, status, 'yes' if take else 'no', number, name, ', '.join(groups), len(members),
                         ', '.join(new_members) if keep else 'new family',
                         ' | '.join(f'{c.by_id[m["id"]]["number"]} {c.by_id[m["id"]]["name"]} = {"/".join(m["options"].values())}'
                                    for m in members if m.get('id') in c.by_id),
                         f'{min(prices)}-{max(prices)}' if prices else '', fam.get('confidence'),
                         '; '.join(why + warn + ([fam['note']] if fam.get('note') else []))])
        for lo in rec.get('left_out') or []:
            stats['left out by agent'] += 1

write_csv(os.path.join(run, 'variants', 'review.csv'),
          ['key', 'decision', 'status', 'will_build', 'parent_number', 'parent_name', 'groups', 'members_count',
           'added to existing family', 'members (number name = option)', 'price_range_eur', 'confidence', 'reason'],
          sorted(rows, key=lambda r: ({'review': 0, 'rejected': 1}[r[2]], r[4])))
json.dump(accepted, open(os.path.join(run, 'variants', 'accepted.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print(json.dumps(stats, ensure_ascii=False, indent=1))
