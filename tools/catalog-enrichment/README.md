# Catalog enrichment (Sivanka)

A rerunnable pipeline that makes the catalogue richer (properties, SEO, later variants) from a
production dump. It runs on the **local** Docker stack only. Changes reach prod as standard
Import/Export CSV files that a person imports in the Admin. Nothing here connects to prod.

Python 3.10+ stdlib and the running local `docker compose` stack. No other dependencies.

## A full run

```bash
cd tools/catalog-enrichment

# 0. Matic downloads the prod dump himself (cPanel / his own SSH), e.g. ~/Desktop/sivanka/backup.sql
./enrich.sh restore ~/Desktop/sivanka/backup.sql   # backs up the local DB first, then replaces it
./enrich.sh collapse --apply                       # only if the run has collapse-plan.json (old hand-made variants)
./enrich.sh extract                                # new run in runs/<timestamp>/ + summary.md
#    second pass for inactive products: props-prepare/seo-prepare --scope inactive --append,
#    variants-prepare --scope all --hints <earlier accepted.json>

./enrich.sh props-prepare                          # only products new or changed since the last finished run
#    agents read runs/<run>/props/in/batch-N.json following stages/props_SPEC.md
#    and write runs/<run>/props/out/batch-N.json (one agent per batch)
./enrich.sh props-validate                         # -> props/review.csv
./enrich.sh seo-prepare --gsc ~/Desktop/<Search Console export>.zip   # rewrites ALL products' SEO
#    agents write runs/<run>/seo/out/batch-N.json following stages/seo_SPEC.md
#    (one agent can do props + SEO of the same batch number: the batches hold the same products)
./enrich.sh seo-validate                           # -> seo/review.csv
./enrich.sh variants-prepare                       # candidate clusters of near-identical names
#    agents write runs/<run>/variants/out/batch-N.json following stages/variants_SPEC.md
./enrich.sh variants-validate                      # -> variants/review.csv (every family needs an approve)
./enrich.sh categories-prepare                     # products without a category
#    agents write runs/<run>/categories/out/batch-N.json following stages/categories_SPEC.md
./enrich.sh categories-validate                    # -> categories/review.csv
#    optional: runs/<run>/fixes/fixes.json (catalogue corrections, see the 2026-11-03 run's make_fixes.py)

#    a person fills the `decision` column (approve / reject) of the review files
./enrich.sh review runs/<run>/{props,seo,variants}/review.csv
./enrich.sh props-validate && ./enrich.sh seo-validate && ./enrich.sh variants-validate   # decisions apply
./enrich.sh build                                  # -> import/NN-*.csv + import/README.md (order, profiles)

./enrich.sh import-local                           # LOCAL: every import file in order + fixes-manual.json
./enrich.sh verify                                 # only the planned changes happened?
#    import the same files on PROD, then:
./enrich.sh finish                                 # remember which products were read
```

`RUN=<name> ./enrich.sh …` works on a run other than the latest.

## What is kept where

| Path | In git | Content |
|---|---|---|
| `state/decisions.json` | yes | every approve/reject, keyed by `kind\|product number\|group\|value`. Product numbers are Minimax codes, so decisions survive any dump. A rejected proposal is never offered again. |
| `state/content-hashes.json` | yes | hash of name + short description + description per product number, as of the last finished run. `props-prepare` skips unchanged products (`--all` reads everything). |
| `runs/<run>/` | no | data extract, snapshot, review files, import files, rollback lists. Contains shop data: stays local. |
| `runs/backups/` | no | the local DB as it was before each `restore` |

Seeded on 2026-11-03 from the September 2026 runs: the 25 proposals rejected in the manual review,
and the content hashes of the 1,276 products read back then.

## Rules the stages keep

- Ids for new groups, options (and later variant parents) are deterministic (`lib/catalog.py`,
  same namespace as September), so importing a file twice changes nothing.
- Existing values win. Imports only add properties; they never remove or overwrite.
- A property needs a verbatim quote from the product's own text (`props_validate.py`).
- Agents never propose properties for variant parents beyond groups that cannot differ per variant. The build
  copies each variant's own option values (Barva, Velikost, …) into its properties (`11-variant-properties.csv`),
  because the Specifications table lists properties only; it only adds groups the variant has no value for yet.
- Import/Export gotchas: blank cells never clear a value; never use `--rollbackOnError` with
  `import:entity` (it never commits).

## Stages

| Stage | File | Reads | Writes |
|---|---|---|---|
| restore | `stages/restore.sh` | dump file | local DB (domains → local, mail off, Minimax tasks inactive, order export off, admin/shopware) |
| extract | `stages/extract.sh`, `snapshot.sh`, `summary.py` | local DB (SELECT only) | `data/*.jsonl`, `snapshot.tsv`, `summary.md` |
| props | `props_prepare.py`, `props_SPEC.md`, `props_validate.py` | data, agent output, decisions | `props/review.csv`, `props/accepted.jsonl` |
| seo | `seo_prepare.py`, `seo_SPEC.md`, `seo_validate.py` | data, Search Console export, agent output | `seo/review.csv`, `seo/accepted.jsonl` |
| variants | `variants_prepare.py`, `variants_SPEC.md`, `variants_validate.py` | data, agent output, decisions | `variants/review.csv`, `variants/accepted.json` |
| review | `review.py` | edited review CSVs | `state/decisions.json` |
| build | `build.py` | `*/accepted.jsonl` | `import/`, `rollback/` |
| verify | `snapshot.sh`, `verify.py` | local DB after the import | pass/fail per product |
| finish | `finish.py` | `props/all.json` | `state/content-hashes.json` |

## Variant families: what the import does

Every family member is an existing Minimax item and keeps its number, name, price and stock. The build
writes: `04` option values, `05` new empty parents (`parent_<NAME>`, unknown to Minimax so the sync
ignores them), `06` attach the members (`parent_id` + `optionIds`; Shopware creates the configurator settings
itself), `07` which variant listings show (custom profile `sivanka_variant_listing`, create
it once in the Admin: product, update only, `id`, `productNumber`, `variantListingConfig` →
`variant_listing_config`). Undo: "Split variants" on the parents (see `rollback/README.md`).

## One-time cleanup done on 2026-11-03: the old hand-made variants

The prod data had 29 parents with 77 variants. Every parent is a Minimax item (it held the stock);
none of the 77 variant numbers (`200208.SR`, `2415.1B`, …) exists in Minimax (checked one by one
with `optiweb:sync product --dry-run --setId=<number>`, result in the run's `minimax-family-check.tsv`).
Matic's decision: keep only Minimax codes. Locally done with `php/collapse-variants.php <plan>
--apply` (plan in the run's `collapse-plan.json`). **On prod, before importing any variant file**:

1. Admin > Products: select the 29 parents in `collapse-plan.json` → "Split variants", **delete
   parent OFF** (the parent stays as the simple Minimax product).
2. Delete the 77 former variants listed under `delete` in the same file (Admin product list,
   search by number, bulk delete). None has ever been ordered.
