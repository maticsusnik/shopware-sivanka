#!/usr/bin/env python3
"""build <run dir> : accepted proposals -> numbered Import/Export files in <run>/import/.

Files are imported in their numeric order, each with the profile named in import/README.md.
Steps without accepted rows are skipped. Rollback information goes to <run>/rollback/.
"""
import collections
import json
import os
import re
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'lib'))
from catalog import Catalog, stable_id, write_csv  # noqa: E402

run = sys.argv[1]
c = Catalog(run)
imp, rb = os.path.join(run, 'import'), os.path.join(run, 'rollback')
for d in (imp, rb):
    os.makedirs(d, exist_ok=True)
    for f in os.listdir(d):
        os.remove(os.path.join(d, f))


def jsonl(*parts):
    path = os.path.join(run, *parts)
    return [json.loads(l) for l in open(path, encoding='utf-8')] if os.path.exists(path) else []


NEW_COLOR_HEX = {'Rjava': '#8b5a2b', 'Bež': '#d8c3a5', 'Krem': '#f5ecd7', 'Siva': '#9e9e9e', 'Roza': '#f4a6c1',
                 'Oranžna': '#f28c28'}
steps = []

# catalogue fixes (fixes/fixes.json): checked against the run data, then split by how they can be applied
props = jsonl('props', 'accepted.jsonl')
fix_path = os.path.join(run, 'fixes', 'fixes.json')
fixes = json.load(open(fix_path, encoding='utf-8')) if os.path.exists(fix_path) else []
fix_texts, fix_local, fix_warn = {}, [], []
raw_field = {'name': 'name', 'description': 'description', 'short_description': 'short_description'}
for fx in fixes:
    p = c.by_id.get(fx['id'])
    if not p:
        fix_warn.append(f"{fx['number']}: product not in the run")
        continue
    have = {f'{g}: {v}' for g, vs in c.existing(p).items() for v in vs}
    for r in fx['remove']:
        if r not in have:
            fix_warn.append(f"{fx['number']}: '{r}' is not on the product")
            continue
        g, v = r.split(': ', 1)
        gid, oid, _, _ = c.resolve_option(g, v)
        fix_local.append(dict(kind='remove_property', product_id=p['id'], number=p['number'], option_id=oid, value=r, why=fx['why']))
    for a in fx['add']:
        g, v = a.split(': ', 1)
        if a in have:
            continue
        gid, oid, new_opt, new_grp = c.resolve_option(g, v)
        props.append(dict(product_id=p['id'], number=p['number'], group=g, value=v, group_id=gid, option_id=oid,
                          new_option=new_opt, new_group=new_grp))
    for t in fx['text']:
        cur = fix_texts.get((p['id'], t['field'])) or p.get(raw_field[t['field']]) or ''
        if t['find'] not in cur:
            fix_warn.append(f"{fx['number']}: '{t['find']}' not found in {t['field']}")
            continue
        fix_texts[(p['id'], t['field'])] = cur.replace(t['find'], t['replace'])
for (pid, field), val in fix_texts.items():
    if field == 'short_description':
        fix_local.append(dict(kind='short_description', product_id=pid, number=c.by_id[pid]['number'], value=val))

# 01 new property groups/options ----------------------------------------------------------
new_opts = {a['option_id']: a for a in props if a['new_option']}
if new_opts:
    n = write_csv(os.path.join(imp, '01-property-options.csv'),
                  ['id', 'color_hex_code', 'name', 'group_id', 'group_display_type', 'group_sorting_type', 'group_name'],
                  [[oid, NEW_COLOR_HEX.get(a['value'], '') if a['group'] == 'Barva' else '', a['value'], a['group_id'],
                    'text' if a['new_group'] else '', 'alphanumeric' if a['new_group'] else '', a['group']]
                   for oid, a in sorted(new_opts.items(), key=lambda x: (x[1]['group'], x[1]['value']))])
    steps.append(('01-property-options.csv', 'Default properties (`default_properties`)', n,
                  f'{len({a["group"] for a in new_opts.values() if a["new_group"]})} new groups. '
                  'New groups become storefront filters: check them in Settings > Properties.'))

# 02 product -> property assignments (additive) --------------------------------------------
per_product = collections.defaultdict(list)
for a in props:
    per_product[a['product_id']].append(a['option_id'])
if per_product:
    n = write_csv(os.path.join(imp, '02-product-properties.csv'), ['id', 'product_number', 'propertyIds'],
                  [[pid, c.by_id[pid]['number'], '|'.join(ids)] for pid, ids in per_product.items()])
    steps.append(('02-product-properties.csv', 'Default product (`default_product`)', n,
                  'Adds properties, never removes any.'))
    write_csv(os.path.join(rb, '02-product-properties-added.csv'), ['product_id', 'product_number', 'option_id', 'group', 'value'],
              [[a['product_id'], a['number'], a['option_id'], a['group'], a['value']] for a in props])

# 03 SEO for products that had none ----------------------------------------------------------
seo = jsonl('seo', 'accepted.jsonl')
if seo:
    n = write_csv(os.path.join(imp, '03-product-seo.csv'),
                  ['id', 'product_number', 'meta_title', 'meta_description', 'keywords'],
                  [[a['product_id'], a['number'], a.get('meta_title'), a.get('meta_description'), a.get('keywords')]
                   for a in seo])
    steps.append(('03-product-seo.csv', 'Sivanka – product SEO update (`sivanka_product_seo_update`)', n,
                  'Rewrites title, description and keywords of every listed product.'))

# categories for products that had none (also feeds the variant parents below) ----------------
cats = jsonl('categories', 'accepted.jsonl')
new_cats = {a['product_id']: a['categories'] for a in cats}

# 04-08 variants (only families a person approved) -------------------------------------------
fams_path = os.path.join(run, 'variants', 'accepted.json')
fams = json.load(open(fams_path, encoding='utf-8')) if os.path.exists(fams_path) else []
SALES_CHANNEL = '01993a7b6f8672959e218921a07085a5'   # storefront "Sivanka"
MEDIA_GROUPS = {'Barva', 'Motiv', 'Odtenek'}


def natural(v):
    return [int(t) if t.isdigit() else t.casefold() for t in re.split(r'(\d+)', str(v).replace(',', '.'))]
if fams:
    var_opts, parents, children, conf, listing = {}, [], [], [], []
    media_options = 0
    for fam in fams:
        parents.append([fam['parent_id'], fam['parent_number'], 1 if fam.get('active', True) else 0, 0, fam['parent_name'],
                        f"{fam['price_net']:.4f}", f"{fam['price_gross']:.2f}", fam['tax_id'],
                        '|'.join(sorted(set(fam['categories']) | {x for m in fam['members'] for x in new_cats.get(m['id'], [])})),
                        SALES_CHANNEL])
        per_group = collections.defaultdict(dict)          # group -> option id -> (value, cover media of a member)
        for m in fam['members']:
            oids = []
            cover = c.by_id[m['id']].get('cover_media_id')
            for g in fam['groups']:
                gid, oid, new_opt, new_grp = c.resolve_option(g, m['options'][g])
                oids.append(oid)
                if new_opt and oid not in new_opts:
                    var_opts[oid] = dict(group=g, value=m['options'][g], group_id=gid, new_group=new_grp)
                val, have = per_group[g].get(oid, (m['options'][g], None))
                per_group[g][oid] = (val, have or cover)
            children.append([m['id'], fam['parent_id'], m['number'], '|'.join(oids)])
        for g, opts in per_group.items():
            covers = [cv for _, cv in opts.values()]
            # a picture per option where it tells the options apart: colour/motif, at least half of the options have
            # their own photo (an option without one shows its text label), and not all photos alike
            use_media = g in MEDIA_GROUPS and sum(1 for x in covers if x) * 2 >= len(covers) and len(set(filter(None, covers))) > 1
            media_options += sum(1 for x in covers if x) if use_media else 0
            for pos, (oid, (val, cv)) in enumerate(sorted(opts.items(), key=lambda x: natural(x[1][0]))):
                conf.append([stable_id('configurator', fam['parent_id'], oid), fam['parent_id'], oid, pos,
                             (cv or '') if use_media else ''])
        # Listing: no parent card and no single "main" variant; every variant group is expanded, so each
        # combination (e.g. every colour, or every colour x size) is its own card. Shopware ignores the
        # expansion while mainVariantId or displayParent is set (VariantListingUpdater).
        group_cfg = []
        for g in fam['groups']:
            gid = c.resolve_option(g, fam['members'][0]['options'][g])[0]
            covers = [cv for _, cv in per_group[g].values()]
            image = g in MEDIA_GROUPS and sum(1 for x in covers if x) * 2 >= len(covers) and len(set(filter(None, covers))) > 1
            group_cfg.append({'id': gid, 'representation': 'image' if image else 'box', 'expressionForListings': True})
        listing.append([fam['parent_id'], fam['parent_number'],
                        json.dumps({'displayParent': False, 'configuratorGroupConfig': group_cfg})])
    if var_opts:
        n = write_csv(os.path.join(imp, '04-variant-options.csv'),
                      ['id', 'name', 'group_id', 'group_display_type', 'group_sorting_type', 'group_name'],
                      [[oid, a['value'], a['group_id'], 'text' if a['new_group'] else '',
                        'alphanumeric' if a['new_group'] else '', a['group']]
                       for oid, a in sorted(var_opts.items(), key=lambda x: (x[1]['group'], x[1]['value']))])
        steps.append(('04-variant-options.csv', 'Default properties (`default_properties`)', n,
                      'Option values the variant selectors need.'))
    n = write_csv(os.path.join(imp, '05-variant-parents.csv'),
                  ['id', 'product_number', 'active', 'stock', 'name', 'price_net', 'price_gross', 'tax_id',
                   'categories', 'sales_channel'], parents)
    n_existing = sum(1 for f in fams if f.get('existing'))
    steps.append(('05-variant-parents.csv', 'Default product (`default_product`)', n,
                  'Empty parents, numbers `parent_…` (Minimax does not know them and leaves them alone): '
                  f'{n - n_existing} new, {n_existing} existing ones updated (name, price, categories).'))
    n = write_csv(os.path.join(imp, '06-variant-configurator.csv'), ['id', 'product_id', 'option_id', 'position', 'media_id'], conf)
    steps.append(('06-variant-configurator.csv', 'Default variant configuration settings (`default_variant_configuration_settings`)',
                  n, f'Selector options per parent in natural order; {media_options} colour/motif options show the variant photo. '
                  'Must come before 07: attaching a child reuses the row for the same parent + option.'))
    n = write_csv(os.path.join(imp, '07-variant-children.csv'), ['id', 'parent_id', 'product_number', 'optionIds'], children)
    steps.append(('07-variant-children.csv', 'Default product (`default_product`)', n,
                  'Attaches existing products to their parent. They keep their own number, name, price and stock.'))
    emptied = sorted({pid for f in fams for pid in f.get('merged_parents') or []})
    if emptied:
        json.dump([dict(id=pid, number=c.by_id[pid]['number'], name=c.by_id[pid]['name'],
                        merged_into=next(f['parent_number'] for f in fams if pid in (f.get('merged_parents') or [])))
                   for pid in emptied],
                  open(os.path.join(imp, 'delete-emptied-parents.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
        steps.append(('delete-emptied-parents.json', 'not importable: delete after 07 (they have no variants left)',
                      len(emptied), 'Parents whose variants moved into another family by a merge.'))
    n = write_csv(os.path.join(imp, '08-variant-listing.csv'), ['id', 'product_number', 'variant_listing_config'], listing)
    steps.append(('08-variant-listing.csv', 'Sivanka – variant listing (`sivanka_variant_listing`, create once: '
                  'product, update only, mapping id / productNumber / variantListingConfig → variant_listing_config)', n,
                  'Listings show every variant as its own card, split by all variant groups; no parent card.'))
    # 11 the variant's own option values as properties: the product page's Specifications table lists properties
    # only, so without this a colour variant shows no "Barva" there. Only groups the variant has no value for yet
    # (an existing colour name such as Bela next to the option "b. 01" stays, and nothing is removed).
    have_groups = collections.defaultdict(set)
    for fam in fams:
        for m in fam['members']:
            have_groups[m['id']] |= set(c.existing(c.by_id[m['id']]))
    for a in props:
        have_groups[a['product_id']].add(a['group'])
    var_props = []
    for fam in fams:
        for m in fam['members']:
            for g in fam['groups']:
                if g not in have_groups[m['id']]:
                    oid = c.resolve_option(g, m['options'][g])[1]
                    var_props.append(dict(product_id=m['id'], number=m['number'], group=g, value=m['options'][g], option_id=oid))
    if var_props:
        per_var = collections.defaultdict(list)
        for a in var_props:
            per_var[a['product_id']].append(a['option_id'])
        n = write_csv(os.path.join(imp, '11-variant-properties.csv'), ['id', 'product_number', 'propertyIds'],
                      [[pid, c.by_id[pid]['number'], '|'.join(ids)] for pid, ids in per_var.items()])
        steps.append(('11-variant-properties.csv', 'Default product (`default_product`)', n,
                      f'{len(var_props)} variant option values also as properties, so Specifications show them (after 04 and 07).'))
        write_csv(os.path.join(rb, '11-variant-properties-added.csv'), ['product_id', 'product_number', 'option_id', 'group', 'value'],
                  [[a['product_id'], a['number'], a['option_id'], a['group'], a['value']] for a in var_props])
    write_csv(os.path.join(rb, '05-variant-parents-created.csv'), ['parent_id', 'parent_number', 'members'],
              [[f['parent_id'], f['parent_number'], ' '.join(m['number'] for m in f['members'])] for f in fams])
    open(os.path.join(rb, 'README.md'), 'w', encoding='utf-8').write(
        '# Undo\n\nVariants: in the Admin product list select the parents in `05-variant-parents-created.csv` and run '
        '"Split variants" with "delete parent" on. Every child becomes a standalone product again with its own data.\n'
        'Never delete a parent directly: Shopware deletes its variants with it.\n\nProperties: the rows in '
        '`02-product-properties-added.csv` (Import/Export cannot remove assignments; remove them via the API).\n')

rows = collections.defaultdict(dict)
for (pid, field), val in fix_texts.items():
    if field in ('name', 'description'):
        rows[pid][field] = val
if rows:
    n = write_csv(os.path.join(imp, '10-fix-texts.csv'), ['id', 'product_number', 'name', 'description'],
                  [[pid, c.by_id[pid]['number'], f.get('name'), f.get('description')] for pid, f in rows.items()])
    steps.append(('10-fix-texts.csv', 'Default product (`default_product`)', n,
                  'Corrected names/descriptions (fixes/fixes.json). Empty cells change nothing.'))
if fix_local or fix_warn:
    json.dump(fix_local, open(os.path.join(imp, 'fixes-manual.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    steps.append(('fixes-manual.json', 'not importable: locally `php/apply-fixes.php`; on prod by hand in the Admin', len(fix_local),
                  'Removes wrong property values and corrects short descriptions (a custom field).'
                  + (f' {len(fix_warn)} fixes skipped: ' + '; '.join(fix_warn) if fix_warn else '')))

if cats:
    n = write_csv(os.path.join(imp, '09-product-categories.csv'), ['id', 'product_number', 'categories'],
                  [[a['product_id'], a['number'], '|'.join(a['categories'])] for a in cats])
    steps.append(('09-product-categories.csv', 'Default product (`default_product`)', n,
                  'Categories for products that had none (adds, never removes).'))

L = [f'# Import files ({os.path.basename(run)})', '',
     'Import in this order through Settings > Import/Export > Import. A dry run only works for a file whose',
     'dependencies are already imported (02 needs 01, 06 needs 04 and 05, 07 needs 06), so import step by step.', '',
     '**On prod, first:** back up the database (cPanel). If the run has `collapse-plan.json`, do that cleanup',
     'first (tools/catalog-enrichment/README.md, "One-time cleanup"). Create the profile `sivanka_variant_listing`',
     'once if it does not exist yet.', '',
     '| # | File | Profile | Rows | Note |', '|---|---|---|---:|---|']
L += [f'| {i} | `{f}` | {prof} | {n} | {note} |' for i, (f, prof, n, note) in enumerate(steps, 1)]
L += ['', 'After the import: verify locally with `./enrich.sh verify`; on prod spot-check the storefront.']
open(os.path.join(imp, 'README.md'), 'w', encoding='utf-8').write('\n'.join(L) + '\n')
print('\n'.join(L))
