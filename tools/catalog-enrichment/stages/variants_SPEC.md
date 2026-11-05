# Variant families spec (Sivanka)

You get **candidate clusters** of products whose names are nearly identical (input
`<run>/variants/in/batch-N.json`). Decide, per cluster, which products are one product sold in several
versions, and describe each family. Output: `<run>/variants/out/batch-N.json`.

Every product is its own Minimax item with its own number, stock and price. Grouping only changes how the
shop shows them: one product page with a selector (colour, volume, size, length, …), and in category listings
every variant is still its own card.

## Existing families
Some products are already variants: they carry `family` (`parent_id`, `parent_name`, `options` = their current
values). The shop owner wants families to be **complete**:
- **Extend** an existing family with matching standalone products. Example: `Istantaneo 250 ml` has črna and
  rjava; the standalone `ISTANTANEO ČRNA 1L` joins, and the family gets a second group `Prostornina`
  (250 ml / 1 l) next to `Barva`.
- **Merge** existing families that are the same product line differing in one more attribute
  (`Step 4 100 g` and `Step 6 150 g` → one family `Barva` × `Teža`? only if a customer would really choose
  both on one page; two different yarn lines stay separate families).
- To extend or merge, set `keep_parent_id` to the `parent_id` of the family you keep, and list **all** members:
  every current variant of the kept family (and of any family merged into it) plus the new ones. A current
  variant keeps its current option values exactly (same group, same value); you may only add a value for a
  new group. Never drop a current variant from its family.
- A family you would leave exactly as it is: don't output it.

## A real family
- Same product, differing in **one or two** attributes a customer picks: colour/shade, volume (Prostornina),
  weight (Teža), size/diameter (Velikost), width (Širina), length (Dolzina, Dolžina niti), dimensions
  (Dimenzije), needle size, motif, model. Examples: `ZADRGA 3CFC 10CM / 16CM / 20CM` (Dolzina),
  `NAŠITEK N1 … N9` (Motiv), `STEP 4 B.1009 / B.1012` (Barva), `BARVA ZA USNJE … 250mL / 1L` (Prostornina).
- Same type, brand, material and use. Prices may differ with the size; a very different price for the same
  size is a warning sign.
- With two groups, not every combination has to exist (black in 250 ml and 1 l, brown only in 250 ml is fine).

## Not a family
- Different products that share a word (`GUMB` of different models, `PREJA` of different lines).
- Products that differ in 3+ ways, or whose differing part you cannot name as an attribute.
- Packs vs single pieces of the same item (`4 KOS` vs 1) only when the pack size is the only difference; then
  the group is `Količina v pakiranju`.
- Two products with the same combination (true duplicates): keep one in the family, leave the other out and say
  so in `left_out` (`duplicate of <number>`).
- A family of one: skip.

## Per family
- `members`: `{"id": "...", "options": {"Barva": "Črna", "Prostornina": "1 l"}, "evidence": "ČRNA 1L"}`.
  Every member needs a value for every group, and no two members may have the same combination.
- `groups`: Slovene, capitalised. Reuse the shop's groups: Barva, Velikost, Širina, Dolzina, Dimenzije, Teža,
  Prostornina, Dolžina niti, Količina v pakiranju, Debelina, Velikost igle, Motiv, Model, Oblika.
- Option values as in the names, normalised: `16cm`, `25mm`, `250 ml`, `1 l`, `100 g`, `Črna`, `b. 1009`.
  Every value must be readable from that member's own name or description: quote it in `evidence`
  (one fragment per group, joined by ` / `).
- `parent_name`: the shared name in proper Slovene casing without the differing part
  (`Barva za površino usnja Istantaneo`, `Austermann Step 4 preja za pletenje`). When extending, give the
  name that fits the extended family (drop `250 ml` when volume becomes a group).
- `main`: an active member that is typical / best stocked.
- `confidence`: `high` when it is clearly one product in versions, else `medium`.
- `left_out`: standalone products of the cluster you did not put in a family, with a short `why`.

```json
[{"cluster": "c-12", "families": [{
  "keep_parent_id": "<parent_id or omit for a new family>",
  "parent_name": "Barva za površino usnja Istantaneo", "groups": ["Prostornina", "Barva"], "main": "<id>",
  "confidence": "high",
  "members": [{"id": "<id>", "options": {"Prostornina": "1 l", "Barva": "Črna"}, "evidence": "1L / ČRNA"}],
  "note": ""}], "left_out": [{"id": "<id>", "why": "different line"}]}]
```
