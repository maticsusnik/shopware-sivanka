"""Option values read from the product NUMBER (Minimax code) when name and description don't state them.

Rule (Matic, 2026-11-05): a product may take a value from its number only if its number matches a family member's
number exactly except for that value, e.g. member `STEP 100G B.046` = Barva b. 046, candidate `STEP 100G B.043`
-> Barva b. 043; `30549 NIKEL` = Nikelj, `30549 ST.ZLATO` -> Staro zlato.
"""
import re

from values import BARVA

COLOUR_ALIASES = {
    'staro zlato': ['st.zlat', 'st zlat', 'stzlat', 'starozlat', 'staro zlat'], 'nikelj': ['nikel', 'nik', 'ni'],
    'zlata': ['zlat', 'zl', 'z'], 'srebrna': ['srebr', 'sr', 'srebrn'], 'črna': ['črn', 'crn', 'čr', 'č'],
    'bela': ['bel', 'b'], 'bronz': ['bronz', 'br'], 'rjava': ['rjav'], 'siva': ['siv'], 'modra': ['modr', 'moder'],
    'rdeča': ['rdeč', 'rdec'], 'zelena': ['zelen'], 'rumena': ['rumen'], 'bež': ['bež', 'bez', 'beige'],
    'prozorna': ['prozor', 'transparent'],
}


def _colour_of(token):
    t = re.sub(r'[\s.\-/]', '', token.casefold())
    if not t:
        return None
    for colour, alts in COLOUR_ALIASES.items():           # longer colours first in the dict (staro zlato before zlata)
        for alt in [colour] + alts:
            a = re.sub(r'[\s.\-/]', '', alt)
            if t == a or (len(a) >= 3 and len(t) >= 3 and (t.startswith(a) or a.startswith(t))):
                return next((b for b in BARVA if b.casefold() == colour), colour.capitalize())
    return None


def _value_token(number, value):
    """The part of `number` that carries `value`: its digits, or a colour word. -> (start, end, kind) or None."""
    digits = re.findall(r'\d+(?:[.,]\d+)?', value)
    if len(digits) == 1:
        hits = [m for m in re.finditer(r'(?<!\d)(?<!\d[.,])' + re.escape(digits[0]) + r'(?!\d)(?![.,]\d)', number)]
        if len(hits) == 1:
            return hits[0].start(), hits[0].end(), 'digits'
        return None
    if not digits:
        hits = [m for m in re.finditer(r'[A-Za-zČŠŽčšž]+(?:\.[A-Za-zČŠŽčšž]+)?', number) if _colour_of(m.group(0)) == value]
        if len(hits) == 1:
            return hits[0].start(), hits[0].end(), 'colour'
    return None


def template(number, value):
    """Regex that matches numbers equal to `number` except for the value part, with the value part captured."""
    tok = _value_token(number, value)
    if not tok:
        return None, None
    s, e, kind = tok
    if len(re.sub(r'[\s.\-/]', '', number[:s] + number[e:])) < 3:     # the value must not be (almost) the whole number
        return None, None
    part = r'(?<!\d)(\d+(?:[.,]\d+)?)(?!\d)' if kind == 'digits' else r'([A-Za-zČŠŽčšž]+(?:\.[A-Za-zČŠŽčšž]+)?)'
    return re.compile('^' + re.escape(number[:s]) + part + re.escape(number[e:]) + '$'), kind


def value_from_number(candidate_number, member_number, member_value):
    """The value `candidate_number` carries in the member's pattern, formatted like member_value; else None."""
    rx, kind = template(member_number, member_value)
    if not rx:
        return None
    m = rx.match(candidate_number)
    if not m or candidate_number == member_number:
        return None
    if kind == 'digits':
        old = re.findall(r'\d+(?:[.,]\d+)?', member_value)[0]
        return member_value.replace(old, m.group(1), 1)
    return _colour_of(m.group(1))
