# SEO spec (Sivanka, Slovene haberdashery and sewing shop in Kranj)

You write the search-engine fields for products, one by one. Input: `<run>/seo/in/batch-N.json`,
output: `<run>/seo/out/batch-N.json`. Every product is rewritten, including ones that already
have SEO: the current values are given only as a reference (`current`).

Context: Sivanka (sivanka.com) is a shop for haberdashery, sewing, knitting, leather and upholstery
supplies, with a sewing-repair service in Kranj. Customers are mostly Slovene hobbyists and tailors.
Language: **Slovene**, natural and correct (cases, plural forms, č/š/ž).

## Input per product
`id`, `number` (Minimax code), `name` (often ALL CAPS, abbreviated), `short_description`, `description`,
`category` (path), `properties` (`Group: value`), `current` (meta_title / meta_description / keywords).
The batch file also contains `search_terms`: real Google queries that reached the shop, with
impressions. Prefer their wording where it fits the product (e.g. people search "gumbi za oblačila",
"zakovice za usnje", "barva za usnje", "našitki za oblačila", "sukanec za usnje", "gurtne").

## Hard rules
1. **Only facts from the product's own data** (name, descriptions, category, properties). Never invent
   material, size, colour, use, brand, origin, quality claims or prices. Prices, stock and shipping
   promises must not appear. If the data is thin, write less, not more.
2. Never use the product number in the title or description. It belongs only at the end of `keywords`.
3. Don't keyword-stuff, use no ALL CAPS, no exclamation marks, no emoji, no "najboljši", "vrhunski",
   "top", "akcija" unless the description says it.

## Fields
- **meta_title**: at most 60 characters including the suffix ` | Sivanka`. Shape: the product as a customer would search
  it, then the key distinguishing fact (size, colour, material, brand), then ` | Sivanka`. Expand
  abbreviations and fix casing: `PRITISKAČ 7 mm črn` → `Pritiskač 7 mm, črn | Sivanka`;
  `STEP 4 B.1009 PREJA ZA PLETENJE 100G` → `Austermann Step 4 preja za pletenje 100 g, b. 1009 | Sivanka`
  (brand only if it is in the data). If it does not fit, drop the least important fact, never ` | Sivanka`.
- **meta_description**: 120–155 characters, one or two full sentences. What it is, the key facts,
  what it is used for (only if the data says so or the product type makes it obvious, e.g. a zip
  is for clothing/bags). End with a soft call to action that is true: `Na voljo v spletni trgovini
  Sivanka.` or `Kupite v trgovini Sivanka v Kranju ali na spletu.`
- **keywords**: 4–8 comma-separated lowercase terms: the main term in its search form (often
  plural: "gumbi", "zadrge"), 2–4 specific variations (with size/material/colour), the category term,
  a fitting `search_terms` entry if one matches, then the product number last, in its original case.

## Output
A JSON array, one object per input product, in the same order:

```json
[{"id": "<product id>", "meta_title": "...", "meta_description": "...", "keywords": "...",
  "confidence": "high", "note": ""}]
```
`confidence`: `high` normally; `low` if the product data is too thin or contradictory to know what the
product is (then still write the safest minimal version and say why in `note`).
