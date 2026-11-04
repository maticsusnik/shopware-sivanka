# Property extraction spec (Sivanka, Slovene haberdashery shop)

You read products one by one (input: `<run>/props/in/batch-N.json`, output: `<run>/props/out/batch-N.json`) and extract **structured product properties** that are stated in the
product's own `name`, `short_description` or `description`. Output goes to Shopware property filters, so
values must be consistent across products.

## Hard rules
1. **Never invent.** Every property needs `evidence`: a short verbatim substring copied *exactly* (same
   characters, same case, same spacing) from `name`, `short_description` or `description` of THAT product.
   It will be machine-checked; if the substring is not found, the property is discarded.
2. The value must describe **this product itself**, not options you can pick ("na voljo v več barvah",
   "različne velikosti"), not what it is compatible with ("za pas širine 20mm" on a buckle is OK as Velikost 20mm
   only if the name has it), not accessories.
3. Do not derive values from the category alone.
4. Use the groups and formats below. If a clear, filter-worthy fact fits none of them (e.g. zip type code,
   needle type, fabric weave), you may propose a NEW group: Slovene name, capitalised, singular
   (`Vrsta igle`, `Tip izdelka`), and a short value as written in the product text. New groups are
   reviewed by a person, so use them sparingly and keep the same group name across products.
5. If unsure, skip it or mark `"confidence": "medium"`. Only certain facts get `"high"`.
6. `kind: "parent"` = product with variants (colours/sizes are per variant). For parents only output
   Material, Blagovna znamka, Priporočene pletilke, Priporočena kvačka, Teža, Dolžina niti (never Barva/Velikost/…).
7. Don't repeat something already listed in `existing_properties`.
8. Several values in one group are allowed only for **Material** (e.g. composition "50% volna, 50% akril"
   → Material: Volna + Material: Akril). For every other group give at most one value.

## Groups and value formats (Slovene)

| Group | Use for | Value format (examples) |
|---|---|---|
| Barva | colour of this product | exactly one of: Bela, Bronz, Črna, Modra, Nikelj, Prozorna, Rdeča, Rumena, Srebrna, Staro zlato, Vijolična, Zelena, Zlata, Rjava, Bež, Krem, Siva, Roza, Oranžna (metal finishes: nikelj/nikel → Nikelj, staro zlato → Staro zlato). Multicolour/assorted → skip |
| Velikost | nominal size/diameter of hardware: buttons, snaps, rivets, rings, buckles, magnets, carabiners, eyelets | `15mm`, `1,5mm` (comma decimals, no space) |
| Širina | width of tapes, ribbons, elastics, webbing, lace, velcro, straps | `25mm` |
| Dolzina | length of zips, cords, straps, circular-needle cable, rulers, measuring tapes | `60cm` (convert m → cm only if stated in m for a zip/cord, e.g. 1,5 m → `150cm`) |
| Dimenzije | two/three measurements of one item (canvas, frame, board, cutting mat, clasp outer size) | `40 x 50 cm`, `30 x 43 mm` |
| Teža | net weight of one ball/skein/package | `50 g`, `100 g`, `1 kg` |
| Dolžina niti | length of yarn/thread on one ball/spool | `200 m`, `1000 m` |
| Količina v pakiranju | pieces per package | `6 kos` |
| Material | material/fibre, one value per material, nominative, capitalised | Volna, Merino volna, Akril, Bombaž, Poliester, Poliamid, Elastan, Viskoza, Lan, Svila, Alpaka, Moher, Kašmir, Lurex, Mikrovlakna, Bambus, Les, Jeklo, Nerjaveče jeklo, Medenina, Aluminij, Kovina, Plastika, PVC, Usnje, Umetno usnje, Guma, Steklo, Papir, Filc, Polipropilen, Silikon. (merceriziran bombaž → Bombaž; poliestrski → Poliester; akrilna → Akril; "volna" in "gobelin z volno" is an included yarn, not the material of the canvas → skip) |
| Oblika | shape | Kvadrat, Pravokotna, U oblika, Okrogla, Ovalna, Trikotna, Srce |
| Blagovna znamka | brand explicitly named | official spelling: Prym, KnitPro, Addi, Schmetz, Círculo, Zweigart, KAI, Texi, Pfaff, Austermann, Svig, Lane Mondial, YarnArt, Gütermann, DMC, … |
| Priporočene pletilke | recommended knitting-needle size for a yarn | `3-3,5 mm`, `4 mm` (range with hyphen, comma decimals, space before mm) |
| Priporočena kvačka | recommended crochet-hook size for a yarn | same format as above |
| Velikost igle | the size of THIS knitting needle / crochet hook product | `3,5 mm` |
| Debelina | thickness of sheet material (rubber, leather, felt, soles) | `1,2 mm`, `10 mm` |
| Vrsta zadrge | zip type | Deljiva, Nedeljiva, Skrita, Reverzibilna |

## Output
Write a JSON array to the output path you are given, one object per input product (including ones with no
properties), in the same order:

```json
[{"id": "<product id>", "properties": [
  {"group": "Material", "value": "Volna", "source": "description", "evidence": "50% volna", "confidence": "high"}
]}]
```
`source` is one of `name`, `short_description`, `description`. Keep `evidence` short (3–60 characters).
