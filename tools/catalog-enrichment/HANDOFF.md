# Handoff: Sivanka catalogue enrichment

Read this before touching the catalogue pipeline. It is written for a Claude Code agent (or a person)
picking up the work with no other context. The how-to for each command is in `README.md`.

## What this is

Sivanka (sivanka.com) is a Slovene haberdashery/sewing shop on Shopware 6.7 (repo root: two levels up).
Products come from the Minimax ERP via the OptiwebSync plugin: Minimax owns **number, price, stock, tax**
(and the name only when it creates a product). Everything else (descriptions, SEO, properties,
categories, variants, `active`) is edited in Shopware.

The customer's data was thin: no properties, no variants, template SEO, 2,600+ products without a category.
This pipeline enriches it from the shop's own data, with a person approving, and ships the result to prod
as **standard Admin Import/Export CSV files**.

## Non-negotiable rules

1. **Never touch prod directly.** Prod is a NEOSERV cPanel host, not on Teleport, so there's no SSH for agents
   (the global safety hook blocks `ssh`/`scp`). Matic downloads the prod dump himself and imports the files
   in the prod Admin himself. Never upload a whole database to prod: orders and Minimax stock change there all the time.
2. **Changes go through Import/Export**, not SQL. The exceptions are only local and documented:
   `php/collapse-variants.php` (one-time variant cleanup) and `php/apply-fixes.php` (property removals and
   short-description edits, which Import/Export can't do). On prod those are manual Admin steps.
3. **Never invent product facts.** Every property needs a verbatim quote from that product's own text, every
   number in an SEO text must appear in the product's data, and variant option values must be readable from
   the member's own name/description. The validators enforce this; don't loosen them to pass bad data.
4. **A person approves.** Agents propose and scripts validate; decisions live in `state/decisions.json`
   (via `./enrich.sh review <csv>`). On 2026-11-03 Matic approved everything locally "so I can check it";
   that's approval for the **local** state, not for prod.
5. Minimax org 239849 is production. The only allowed contact is the read-only
   `bin/console optiweb:sync product --dry-run --setId=<number>` lookup (GET).
6. Local Playwright is headless and can't open the local admin (Caddy certificate). Don't bypass the
   certificate. Use the CLI (`bin/console import:entity`) or the scripts instead.

## Current state (2026-11-03)

Run `runs/2026-11-03-2033/` is built from Matic's prod dump `~/Desktop/sivanka/backup_sivanka_03_11_2026.sql`.

| Area | Result |
|---|---|
| Old variants | 29 hand-made families had variants unknown to Minimax (`200208.SR` …). Collapsed: 29 parents became simple products, 77 fake variants deleted (`collapse-plan.json`, `minimax-family-check.tsv`). |
| Properties | 2,878 new assignments from product texts (active + inactive), 8 new groups: Vrsta sprimnega traku, Prostornina, Vrsta pletilk, Številka zadrge, Debelina igle, Vrsta igle, Sistem igle, Debelina sukanca |
| SEO | Rewritten for 3,776 products (agent-written Slovene, `seo_SPEC.md`, Search Console terms from `gsc/`). Name-only products have minimal, low-confidence texts. |
| Variants | 273 families, 1,235 products. Parents `parent_<NAME>`, children keep their Minimax numbers. Two attributes where it makes sense (e.g. Anne: Barva × Dolžina niti). Colour/motif options show the variant's photo in the picker (290 options; used when at least half of a group's options have their own photo). **Listings show every variant as its own card** (all variant groups `expressionForListings: true`, no main variant, no parent card), as Matic asked. Inactive members stay inactive (hidden). |
| Categories | 2,525 products without a category got one (123 left empty: services, fabric by the metre, unclear names) |
| Fixes | 235 catalogue corrections (`fixes/make_fixes.py` → `fixes.json`): wrong colours/materials/sizes, texts copied from siblings, name typos |
| Open issues | `data-issues.md`: real contradictions, duplicates, missing category branches; needs a person |

Local DB: prod dump + collapse + all import files (`import-local.log`), checked with `./enrich.sh verify`.

## Final prod package (2026-11-04): `runs/2026-11-04-1350/`

Built with the steps below from `~/Desktop/backup_sivanka_04_11_2026.sql` (note: Desktop root, not `sivanka/`).
- Old families were still on prod: collapsed with the 2026-11-03 plan (29/77, none missing).
- `reuse-report.json`: 9 changed, 0 new, 0 gone (4 rinčice edited on prod, 5 PATENT got "Cena/m."). One Opus agent
  redid props + SEO for those 9 into `props|seo/out/batch-delta.json` (sorts before `old-*`, so it wins).
- Matic approved the one new review item (800250 Barva: Srebrna) and a new fix rule in the run's
  `fixes/make_fixes.py` (name + short description say N,N mm, Velikost still says N mm → 700606, 700611).
- Result: props 2,886, SEO 3,776, 273 families / 1,235 products, categories 2,520 (5 fewer: the shop categorised
  them meanwhile), fixes 237 (fixes-manual 70 items). `import-local`: 0 errors; `verify`: only planned changes.
  Storefront checked (cards per variant, SEO, square photo swatches). New oddities in the run's `data-issues.md`.
- Playwright MCP is headless and doesn't trust the Caddy CA; HTML checks work with
  `curl --cacert <caddy root.crt from local-setup-caddy-1:/data/caddy/pki/authorities/local/>`.
- **On prod since 2026-11-04 ~15:00** (done through the prod Admin in a Playwright session Matic logged into,
  every step approved by him): collapse via `/api/_action/optiweb/variant-builder/split` (deleteParent=false, it
  defaults to true!) + sync delete of the 77, profile `sivanka_variant_listing` created, files 01–08, 10, 09
  imported in the Admin Import screen (all 0 errors, counts identical to local), fixes-manual applied via the
  sync API (65 links, 5 short descriptions). Prod storefront checked. `./enrich.sh finish` run; the next run
  only reads products changed after this.

## How the 2026-11-04 package was built (repeat for a newer dump)

Matic puts a fresh prod dump on the Desktop (`~/Desktop/sivanka/backup_sivanka_04_11_2026.sql`; ask if the name
differs). Goal: the same result as the 2026-11-03 run, rebuilt on that dump, ready for prod. Matic approved
everything in that run, and `state/decisions.json` carries the decisions over (keyed by product number).

```bash
cd tools/catalog-enrichment
./enrich.sh restore ~/Desktop/sivanka/backup_sivanka_04_11_2026.sql -y
./enrich.sh extract                                    # creates runs/<new>
cp runs/2026-11-03-2033/collapse-plan.json runs/<new>/ && ./enrich.sh collapse --apply   # if the old families are still there
RUN=<new> ./enrich.sh extract                          # re-extract AFTER the collapse (same run name)
./enrich.sh props-prepare --all --scope all
./enrich.sh seo-prepare --scope all --gsc ~/Desktop/sivanka.com-Performance-on-Search-2026-11-03.zip
./enrich.sh categories-prepare
./enrich.sh reuse 2026-11-03-2033                      # copies agent output, fixes, gsc; writes reuse-report.json
```
- Do **not** run `variants-prepare` unless reuse-report lists new/changed products: it would overwrite the
  reused `clusters.json`. If there are new or changed products, give only those to agents (a few batches) and
  re-run the fixes generator (`python3 runs/<new>/fixes/make_fixes.py`, it reads the new run's data).
- Then: the four validators, approve leftovers only if Matic says so (he approved all on 2026-11-03), `build`,
  `import-local`, `verify`, a storefront check (curl with the Caddy root cert, see above; the headless Playwright MCP does not trust it),
  and the theme must be compiled (`bin/console theme:compile`).
- Hand Matic `runs/<new>/import/` + its README for the prod Admin import.

### Why not upload the local database to prod instead
Matic asked. Not recommended, even on a day without orders:
- The local DB is not a clean copy of prod plus our changes. `restore` changed the sales-channel domain to
  localhost, turned mail delivery off, set the Minimax tasks inactive and order export off, reset the admin
  password, and ran `plugin:update:all` with local plugin code that may be newer than what's deployed on prod
  (schema ahead of prod code). Each of these would have to be reverted exactly before an upload.
- Prod keeps changing without orders too: the Minimax sync writes stock and prices every 15 minutes, customers
  register, carts and sessions are written. An upload resets all of that to the dump time.
- The CSV route was tested end to end locally (0 errors, `verify` OK), changes only the planned fields, and
  can be undone (Split variants, rollback lists).
If Matic still wants it: a fresh dump, rebuild, revert the list above, put prod in maintenance mode, then import
in cPanel. Prod is his to touch; the agent never does it.

## Going to prod (Matic does it, in the prod Admin)

**Code first:** the square photo swatches in the variant picker are a theme change
(`OptiwebTheme/.../scss/pages/_product.scss`, `.is-display-media`). It has to be committed and deployed
(CI, `main` → prod) before the variant import, otherwise the photos show as stretched full-width strips.
`f/catalog-enrichment` also carries the Minimax draft-invoice change (OptiwebSync 1.2.0), so merging it deploys both.

Follow `runs/2026-11-04-1350/import/README.md`. In short:
1. Back up the prod DB (cPanel).
2. Collapse the old variants: Products > select the 29 parents in `collapse-plan.json` > "Split variants" with
   **delete parent OFF**, then bulk-delete the 77 numbers under `delete`.
3. Create the profile `sivanka_variant_listing` once (product, update only, mapping id / productNumber /
   variantListingConfig → `variant_listing_config`).
4. Import `01` … `10` in numeric order. A dry run only works once the files it depends on are in
   (02 needs 01; 06 needs 04+05; 07 needs 06).
5. Do `fixes-manual.json` by hand: remove the listed wrong property values (65 items) and correct the listed
   short descriptions.
6. Then `./enrich.sh finish` (records which products were read, so the next run only reads changes).

If prod has changed since the dump (new products, edits), take a fresh dump and rerun instead of importing old files.

## Rerunning later (new dump)

```bash
cd tools/catalog-enrichment
./enrich.sh restore ~/Desktop/sivanka/<new dump>.sql -y   # backs up the local DB first
./enrich.sh extract                                        # new run folder
./enrich.sh props-prepare            # only new/changed products (state/content-hashes.json)
./enrich.sh seo-prepare --gsc <Search Console export.zip> --only-changed
./enrich.sh variants-prepare --scope all --hints runs/2026-11-03-2033/variants/accepted.json
./enrich.sh categories-prepare
# launch agents: one per batch, Opus, each reads the *_SPEC.md of its stage (prompts: see below)
./enrich.sh props-validate && ./enrich.sh seo-validate && ./enrich.sh variants-validate && ./enrich.sh categories-validate
# Matic fills the decision column (da/ne) in the review CSVs →
./enrich.sh review runs/<run>/*/review.csv && <validate again> && ./enrich.sh build
./enrich.sh import-local && ./enrich.sh verify
```

Existing families: a new member of an existing family changes the family's decision key (it's built from the
member numbers), so the family needs approval again. That's intended: someone looks at the change.

### Agent prompts that worked
- One agent per batch file, Opus, background. At most 20 run at once (session limit), so queue the rest.
- Tell each agent: data work only (no docker/console/SQL), read its SPEC, write `out/batch-N.json`, and self-check
  with python3 by copying the validator logic, **not** by running the validators (they write into the run dir).
- One agent can do properties + SEO for the same batch number: the batches hold the same products.
- Ask for a short final report with data oddities. Those reports fed `data-issues.md` and the fixes.

## Gotchas learned the hard way

- `import:entity --rollbackOnError` never commits (prints success, saves nothing). Never use it.
- Import/Export can't remove property assignments and can't clear fields (an empty cell = no change).
- The `variants` column of default_product creates **new** SKUs (`<parent>.1`). Variants are built from existing
  products instead: parents (05), configurator rows with images (06) **before** attaching children (07), because
  Shopware reuses a configurator row for the same parent + option.
- Shopware creates configurator rows itself when children are attached; a second set with other ids collides.
- Listing expansion (`configuratorGroupConfig[].expressionForListings`) is ignored while `mainVariantId` or
  `displayParent` is set (core `VariantListingUpdater`). The import replaces the whole JSON, so the old main variant is gone.
- Detaching a variant needs number/tax/price/stock/name in the same write; deleting a parent deletes its children.
- The web runs `APP_ENV=dev`, the CLI defaults to prod: clear caches with `-e APP_ENV=dev`.
- `grep -q` in a pipe under `set -o pipefail` reports failure (SIGPIPE). Use `grep -c`.
- Agents name the same concept differently. `lib/values.py:canonical()` merges synonyms (zip codes →
  Številka zadrge, Schmetz Nm sizes → Debelina igle, Transparentna → Prozorna). Extend it rather than accepting a new group.
- Customer search terms (Search Console, 22 days since go-live) are thin but useful wording: "gumbi za oblačila",
  "zakovice za usnje", "barva za usnje", "našitki za oblačila".

## Files

| Path | What |
|---|---|
| `enrich.sh`, `config.sh` | dispatcher, settings |
| `stages/*_SPEC.md` | what the agents must do (props, seo, variants, categories) |
| `stages/*_prepare.py`, `*_validate.py` | batch inputs; checks + review CSVs |
| `stages/build.py`, `verify.py`, `import-local.sh` | import files; before/after check; local import |
| `lib/catalog.py`, `lib/values.py` | data loading, ids, group formats, normalisation |
| `php/` | the two local-only DAL scripts |
| `state/` (git) | decisions + content hashes |
| `runs/` (not in git) | shop data per run, incl. backups of the local DB |
