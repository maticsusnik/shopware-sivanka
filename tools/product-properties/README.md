# Product properties from product names

Derives Shopware property groups + options from product names (and descriptions where
they exist) and writes them back through the Admin API. Pure stdlib Python 3, no deps.

The catalogue has almost no descriptions (175 of 1847 products) and almost no category
assignments, so the product **name** is the signal: it carries the product type, the
colour, the material and the measurements (`ZADRGA 6CFX 70CM`, `ELASTIKA 8MM, ČRNA`,
`VELVET PREJA 100G B.23`, `Sprimni trak PS, 20mm, bel, M`).

## Setup

```bash
export SW_API_URL="http://sivanka.shopware.localhost"   # or the prod URL
export SW_API_ID="SWIA..."                              # integration access key id
export SW_API_SECRET="..."                              # integration secret
```

The integration needs write access (admin role is fine).

## Workflow

```bash
./sw_props.py fetch            # products.json     — all top-level products via Admin API
./sw_props.py extract -v       # assignments.csv   — derived properties + coverage report
./sw_props.py apply --dry-run  # show exactly what would be written
./sw_props.py apply            # upsert groups/options, link them to products
./sw_props.py clear --dry-run  # preview the undo
```

`extract` never touches the shop — iterate on `rules.json` and re-run it until the
report and `assignments.csv` look right, and only then `apply`.

After `apply`, refresh the index so storefront filters pick the properties up:

```bash
docker compose exec shopware php bin/console dal:refresh:index --use-queue
docker compose exec shopware php bin/console messenger:consume async --time-limit=120
```

## Current coverage (local, 1847 products)

| Group             | Products | Options |
|-------------------|---------:|--------:|
| Tip izdelka       |     1648 |      25 |
| Širina / premer   |      557 |      88 |
| Material          |      267 |      12 |
| Barva             |      262 |      19 |
| Teža / pakiranje  |      209 |      15 |
| Dolžina           |      171 |      50 |
| Vsebina pakiranja |       74 |      17 |
| Dimenzije         |       73 |      39 |
| Dolžina navitka   |       54 |      14 |

94.5 % of products get at least one property; 1648 get a product type.

## rules.json

Two rule kinds, evaluated in file order:

- **`keyword`** — a fixed option per matching regex. `first_only: true` makes the group
  single-valued (first match wins), which is why the order inside `Tip izdelka` matters:
  `Gobelini in vezenje` sits above `Preja in volna` so that
  `GOBELIN S PREJICAMI 40X30 cm` is a tapestry kit, not yarn. The last rule in
  `Tip izdelka` is a fallback: a name with a gram weight and nothing else recognisable
  is treated as yarn, which is how this catalogue works.
- **`pattern`** — the option value is captured from the name. Used for `mm`, `cm`, `m`,
  `g`, `NNxNN` and piece counts. `max_per_product` caps how many values one product can
  contribute (1 = first match only). Ranges are kept verbatim (`25-30 mm`).

`Barva` carries a `hexCodes` map so the group can use Shopware's `color` display type
with real swatches.

## Idempotency and undo

- Group/option IDs are `md5("sivanka-props:" + name)`, so re-running `apply` updates the
  same rows instead of creating duplicates.
- Before writing, `apply` looks up existing property groups **by name** and reuses their
  IDs (and their option IDs). That is why the pre-existing `Barva` group is extended
  rather than duplicated.
- Product ↔ property links are additive: `apply` never removes properties that were set
  by hand or by another integration.
- `clear` deletes the groups this tool created outright, and from pre-existing groups
  removes only the options this tool added. It needs the `assignments.csv` from the run
  you want to undo.

## Running against production

Same commands, with `SW_API_URL`/`SW_API_ID`/`SW_API_SECRET` pointing at prod. `fetch`
and `extract` are read-only, so review the report and `assignments.csv` against the real
prod catalogue first — new products there may need extra keywords in `rules.json`.

## Known gaps

- ~100 products get nothing: one-off items whose names are only a brand or model
  (`TORBICA PETTY`, `MEDVLOGA S520`, `SUPERLANA MAXI`). They need either a keyword each
  or manual assignment.
- Zipper-specific attributes in the names (`deljiva` / `nedeljiva` / `skrita`, the
  `6CFO` / `6CFX` codes, size `3`/`5`/`6`/`8`) are not extracted yet — they would make
  good extra groups for the ~99 zippers.
- The shop still contains empty test groups (`Velikost`, `Širina`, `RAL barva`,
  `Dolzina`) with 0 product links; this tool leaves them alone.
