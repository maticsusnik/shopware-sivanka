# Category spec (Sivanka)

Products without any category are invisible in the shop navigation. You assign each one to the shop's
existing category tree. Input `<run>/categories/in/batch-N.json`: `categories` (id + full path) and
`products` (name, short text, properties). Output `<run>/categories/out/batch-N.json`.

## Rules
1. Pick the **most specific** fitting category (a leaf when one fits: `Kovinska galanterija > Zaponke`,
   not `Kovinska galanterija`). Use a parent category only when no child fits.
2. Normally one category. Two only when the product clearly belongs to two branches (e.g. a leather
   thread: `Šivanje > Sukanci` and `Čevljarski … > Sukanci`).
3. Decide from what the product **is** (name, description, properties). Don't guess: if you can't tell
   what the product is, give `[]` and say why. Codes-only names (`KLJUČ 3CF`, `TULIPAN 15`) usually
   stay `[]` unless the text says what they are.
4. Use only ids from the given list. Never invent a category.

Domain hints: zips/sliders/metraža zadrg → Šivanje > Šivalne potrebščine in dodatki (there is no zip
category; `Popravila > Zadrge` is the repair *service*, not products). Yarn → Preja in volna >
Jesen in zima (wool, warm) or Pomlad in poletje (cotton, linen, light). Gurtne by material. Leather
paints/glues/thread/soles/heels/awls → the Čevljarski, usnjarski in tapetniški program branch.

## Output
```json
[{"id": "<product id>", "categories": ["<category id>"], "confidence": "high", "why": "zaponka za pas = kovinska zaponka"}]
```
One object per input product, same order. `confidence`: `high` when obvious, `medium` when plausible.
