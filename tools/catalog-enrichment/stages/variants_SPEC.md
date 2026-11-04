# Variant families spec (Sivanka)

You get **candidate clusters** of standalone products that a script found because their names are
nearly identical (input `<run>/variants/in/batch-N.json`). Decide, per cluster, whether they are one
product sold in several versions, and if so describe the family. Output:
`<run>/variants/out/batch-N.json`.

Every product here is its own Minimax item with its own number, stock and price. Grouping only changes
how the shop shows them: one product page with a selector (colour, size, length, …).

## Second round (inactive products, merging)
- Clusters now contain **inactive** products too (`active: false`, mostly new Minimax items nobody has
  switched on yet). They may join a family; they stay inactive (hidden) until an editor switches them on.
  `main` must be an active member whenever the family has one.
- `approved_family` shows the family a product was approved in during round one (name + groups). Keep those
  families, add matching inactive members, and **merge families that are the same product line differing
  in one more attribute** into one family with two groups. Example: Anne 250 m and Anne 500 m become one
  family with groups `Barva` + `Dolžina niti`, so shade b. 7684 exists as 250 m and as 500 m. Other likely
  cases: yarn lines in two weights (`Teža`), needles in two lengths. Only merge when a customer would
  really pick both attributes on one page.
- Don't break up an approved family unless a member clearly doesn't belong (say why in `note`).

## A real family
- Same product, differing in **one or two** attributes a customer picks: colour, size/diameter,
  width, length, thread colour code, shape, needle size. Examples: `ZADRGA 3CFC 10CM / 16CM / 20CM`
  (Dolžina), `NAŠITEK - N1 … N9` (Motiv), `STEP 4 B.1009 / B.1012` (Barva yarn shade).
- Same type, brand, material and use, and the same category. Prices may differ (a longer zip costs
  more); a very different price is a warning sign.

## Not a family
- Different products that share a word (`GUMB` of different models, `PREJA` of different lines).
- Products that differ in 3+ ways, or whose differing part you cannot name as an attribute.
- Clusters where only some members belong: split them, and leave out the ones that don't fit.
- A family of one: skip.

## Per family
- `members`: the product ids that belong, each with its option value(s): `{"id": "...", "options": {"Dolžina": "16cm"}}`.
  Every member needs a value for every group of the family, and no two members may have the same combination.
- `groups`: the attribute names, Slovene, capitalised. Reuse the shop's existing groups when they
  fit (Barva, Velikost, Širina, Dolzina, Dimenzije, Teža, Dolžina niti, Količina v pakiranju, Material,
  Oblika); otherwise a new name (Motiv, Odtenek, Model).
- Option values: as in the product names, normalised to the group's format (`16cm`, `25mm`, `Črna`,
  `b. 1009`). Every value must be readable from that member's own name or description: quote it in
  `evidence`.
- `parent_name`: the shared name in proper Slovene casing, without the differing part
  (`Zadrga 3CFC skrita`, `Austermann Step 4 preja za pletenje 100 g`).
- `main`: the member that is shown in category listings (the most typical or best-stocked one).
- `confidence`: `high` when it is clearly one product in versions, else `medium`.

```json
[{"cluster": "c-12", "families": [{
  "parent_name": "Zadrga 3CFC", "groups": ["Dolzina"], "main": "<id>", "confidence": "high",
  "members": [{"id": "<id>", "options": {"Dolzina": "10cm"}, "evidence": "10CM"}],
  "note": ""}], "left_out": [{"id": "<id>", "why": "different zip type (deljiva)"}]}]
```
