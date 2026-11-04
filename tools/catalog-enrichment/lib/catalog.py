"""Shared helpers for the catalog enrichment stages. Stdlib only, Python 3.10+.

Every stage reads a run directory (runs/<timestamp>/) and writes files into it. Nothing here
talks to Shopware: data comes from read-only SELECTs (extract.sh), changes leave as CSV files
shaped for Shopware's standard Import/Export (delimiter ';', enclosure '"', UTF-8).
"""
import csv
import hashlib
import html
import json
import os
import re
import uuid

CE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STATE = os.path.join(CE_DIR, 'state')
# Same namespace as the September 2026 runs, so the same group/value gets the same id forever.
NS = uuid.UUID('7d0c2f6e-3a52-4b1e-9d1a-5ec1a0ca7a10')


# ------------------------------------------------------------------ text
def norm(s):
    return re.sub(r'\s+', ' ', (s or '')).strip().casefold()


def squash(s):
    return re.sub(r'\s+', '', (s or '')).casefold()


def strip_html(s):
    s = re.sub(r'<(br|/p|/li|/h\d|/div)[^>]*>', '. ', s or '', flags=re.I)
    s = re.sub(r'<[^>]+>', ' ', s)
    s = html.unescape(s).replace('​', '').replace('\\', ' ')
    s = re.sub(r'\s+', ' ', s).strip()
    s = re.sub(r'(\s*\.\s*){2,}', '. ', s)
    s = re.sub(r'\s+([.,;:!?])', r'\1', s)
    return s.strip(' .') + ('.' if s.strip(' .') else '')


# ------------------------------------------------------------------ ids
def group_id(group):
    return uuid.uuid5(NS, f'group|{norm(group)}').hex


def option_id(group, value):
    return uuid.uuid5(NS, f'option|{norm(group)}|{norm(value)}').hex


def stable_id(*parts):
    """Deterministic id for anything else this pipeline creates (variant parents, configurator rows)."""
    return uuid.uuid5(NS, '|'.join(norm(p) for p in parts)).hex


# ------------------------------------------------------------------ run data
class Catalog:
    def __init__(self, run_dir):
        self.run = run_dir
        d = os.path.join(run_dir, 'data')
        self.products = [json.loads(l) for l in open(os.path.join(d, 'products.jsonl'), encoding='utf-8')]
        self.categories = [json.loads(l) for l in open(os.path.join(d, 'categories.jsonl'), encoding='utf-8')]
        self.options = [json.loads(l) for l in open(os.path.join(d, 'options.jsonl'), encoding='utf-8')]
        for p in self.products:
            for k in ('categories', 'properties', 'options', 'media', 'price_gross', 'price_net'):
                p[k] = p.get(k) or []
            p['kind'] = 'variant' if p['parent'] else 'parent' if p['children'] else 'simple'
        self.by_id = {p['id']: p for p in self.products}
        self.cat = {c['id']: c for c in self.categories}
        self.opt = {o['id']: o for o in self.options}
        self.groups = {}
        for o in self.options:
            g = self.groups.setdefault(o['group'], {'id': o['gid'], 'options': {}})
            g['options'][squash(o['name'])] = (o['id'], o['name'])

    def text(self, p):
        """Everything a product says about itself, HTML stripped."""
        return ' '.join(x for x in (p['name'], strip_html(p['short_description']), strip_html(p['description'])) if x)

    def existing(self, p):
        """{group name: {value, ...}} of the product's current properties."""
        out = {}
        for oid in p['properties']:
            o = self.opt.get(oid)
            if o:
                out.setdefault(o['group'], set()).add(o['name'])
        return out

    def resolve_option(self, group, value):
        """-> (group_id, option_id, is_new_option, is_new_group); reuses existing rows by name."""
        g = self.groups.get(group)
        if g and squash(value) in g['options']:
            return g['id'], g['options'][squash(value)][0], False, False
        return (g['id'] if g else group_id(group)), option_id(group, value), True, g is None

    def cat_path(self, c):
        ids = [x for x in (c['path'] or '').split('|') if x]
        return [self.cat[i] for i in ids if i in self.cat] + [c]

    def main_category(self, p):
        cs = sorted((self.cat[i] for i in p['categories'] if i in self.cat), key=lambda c: (-c['level'], c['name']))
        return cs[0] if cs else None


def in_scope(p, scope):
    """active = what the shop shows (active top-level products and parents); inactive = the rest; all = both."""
    if p['kind'] == 'variant' or not p['name']:
        return False
    active = bool(p['active']) or p['kind'] == 'parent'
    return scope == 'all' or (scope == 'active') == active


def content_hash(p):
    return hashlib.sha1('\x1f'.join(p.get(k) or '' for k in ('name', 'short_description', 'description'))
                        .encode('utf-8')).hexdigest()


# ------------------------------------------------------------------ state (committed to git)
def load_state(name, default):
    path = os.path.join(STATE, name)
    return json.load(open(path, encoding='utf-8')) if os.path.exists(path) else default


def save_state(name, data):
    os.makedirs(STATE, exist_ok=True)
    tmp = os.path.join(STATE, name + '.tmp')
    with open(tmp, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=1, sort_keys=True)
        f.write('\n')
    os.replace(tmp, os.path.join(STATE, name))


def decision_key(kind, product_number, group, value):
    """Keyed by product NUMBER (the Minimax code), so a decision survives any dump."""
    return f'{kind}|{product_number}|{group}|{norm(value)}'


# ------------------------------------------------------------------ csv
def write_csv(path, header, rows):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w', newline='', encoding='utf-8') as f:
        w = csv.writer(f, delimiter=';', quotechar='"', quoting=csv.QUOTE_MINIMAL, lineterminator='\n')
        w.writerow(header)
        for r in rows:
            w.writerow(['' if v is None else str(v).replace('\\', '/') for v in r])
    return len(rows)


def read_csv(path):
    return list(csv.DictReader(open(path, encoding='utf-8-sig'), delimiter=';'))
