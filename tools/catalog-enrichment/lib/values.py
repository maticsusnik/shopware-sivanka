"""Property groups the shop uses, their value formats, and the normaliser every stage shares."""
import re
import unicodedata

from catalog import norm, squash

BARVA = ['Bela', 'Bronz', 'Črna', 'Modra', 'Nikelj', 'Prozorna', 'Rdeča', 'Rumena', 'Srebrna', 'Staro zlato',
         'Vijolična', 'Zelena', 'Zlata', 'Rjava', 'Bež', 'Krem', 'Siva', 'Roza', 'Oranžna']
MATERIAL_STEMS = {
    'Volna': ['voln', 'wool', 'wolle'], 'Merino volna': ['merino'], 'Akril': ['akril', 'acryl'], 'Bombaž': ['bomba', 'cotton'],
    'Poliester': ['poliest', 'polyest', 'pes'], 'Poliamid': ['poliamid', 'polyamid', 'najlon', 'nylon'],
    'Elastan': ['elastan', 'spandex', 'lycra'], 'Viskoza': ['viskoz', 'viscos'], 'Lan': ['lan', 'linen'],
    'Svila': ['svil', 'silk'], 'Alpaka': ['alpak', 'alpac'], 'Moher': ['moher', 'mohair'], 'Kašmir': ['kašmir', 'cashmere'],
    'Lurex': ['lurex'], 'Mikrovlakna': ['mikrovlak', 'mikrofib'], 'Bambus': ['bambus', 'bamboo'], 'Les': ['les'],
    'Jeklo': ['jekl', 'steel'], 'Nerjaveče jeklo': ['nerjav', 'inox'], 'Medenina': ['medenin', 'mesing'],
    'Aluminij': ['alumin'], 'Kovina': ['kovin', 'metal', 'železo', 'železa', 'cink'], 'Plastika': ['plastik', 'plastič'],
    'PVC': ['pvc'], 'Usnje': ['usnj'], 'Umetno usnje': ['umetn'], 'Guma': ['gum'], 'Steklo': ['stekl'], 'Papir': ['papir', 'paper'], 'Filc': ['filc', 'felt'], 'Polipropilen': ['polipropil', 'polypropyl'],
    'Silikon': ['silikon', 'silicon'],
}
SIZE_MM = r'\d+(?:,\d+)?mm'
RANGE_MM = r'\d+(?:,\d+)?(?:-\d+(?:,\d+)?)? mm'
FMT = {
    'Velikost': SIZE_MM, 'Širina': SIZE_MM, 'Dolzina': r'\d+(?:,\d+)?cm',
    'Dimenzije': r'\d+(?:,\d+)? x \d+(?:,\d+)?(?: x \d+(?:,\d+)?)? (?:mm|cm|m)',
    'Teža': r'\d+(?:,\d+)? (?:g|kg)', 'Dolžina niti': r'\d+(?:,\d+)? m', 'Količina v pakiranju': r'\d+ kos',
    'Priporočene pletilke': RANGE_MM, 'Priporočena kvačka': RANGE_MM, 'Velikost igle': RANGE_MM,
    'Debelina': r'\d+(?:,\d+)? mm',
}
ENUM = {'Barva': BARVA, 'Vrsta zadrge': ['Deljiva', 'Nedeljiva', 'Skrita', 'Reverzibilna'],
        'Oblika': ['Kvadrat', 'Pravokotna', 'U oblika', 'Okrogla', 'Ovalna', 'Trikotna', 'Srce'],
        'Material': list(MATERIAL_STEMS)}
FREE = {'Blagovna znamka'}
MULTI = {'Material'}
PARENT_OK = {'Material', 'Blagovna znamka', 'Priporočene pletilke', 'Priporočena kvačka', 'Teža', 'Dolžina niti'}
BRANDS = {squash(b): b for b in ['Prym', 'KnitPro', 'Addi', 'Schmetz', 'Círculo', 'Zweigart', 'KAI', 'Texi', 'Pfaff',
                                 'Austermann', 'Svig', 'Lane Mondial', 'YarnArt', 'Gütermann', 'DMC']}


def unaccent(s):
    return ''.join(ch for ch in unicodedata.normalize('NFD', s) if unicodedata.category(ch) != 'Mn')


def num(n):
    return float(n.replace(',', '.'))


def numbers(s):
    return re.findall(r'\d+(?:[.,]\d+)?', s)


def clean_value(group, v):
    v = re.sub(r'\s+', ' ', (v or '').strip())
    if group in FMT:
        v = re.sub(r'(?<=[\d\s])(MM|CM|KG|G|M)$', lambda m: m.group(1).lower(), v)
        v = v.replace('.', ',').replace('–', '-').replace('—', '-')
        v = re.sub(r'\s*-\s*', '-', v)
        v = re.sub(r'\s*[x×X]\s*', ' x ', v)
        if group in ('Velikost', 'Širina', 'Dolzina'):
            v = v.replace(' ', '')
        if group in ('Priporočene pletilke', 'Priporočena kvačka', 'Velikost igle', 'Debelina'):
            v = re.sub(r'(\d)\s*mm$', r'\1 mm', v)
        if group == 'Dimenzije':
            v = re.sub(r'(\d)\s*(mm|cm|m)$', r'\1 \2', v)
        if group in ('Teža', 'Dolžina niti'):
            v = re.sub(r'(\d)\s*(g|kg|m)$', r'\1 \2', v)
        if group == 'Količina v pakiranju':
            v = re.sub(r'(\d+)\s*(kos|kom|kosov)\.?$', r'\1 kos', v)
        v = re.sub(r'(\d),0+(?!\d)', r'\1', v)
    if group == 'Blagovna znamka':
        v = BRANDS.get(squash(v), v)
    for e in ENUM.get(group, []):
        if norm(e) == norm(v):
            v = e
    return v


def canonical(group, value):
    """One name per concept across agents and stages -> (group, value)."""
    g, v = (group or '').strip(), re.sub(r'\s+', ' ', str(value or '').strip())
    if g in ('Oznaka zadrge', 'Številka zadrge'):
        m = re.match(r'\d+', v)
        return 'Številka zadrge', (m.group(0) if m else v)
    if g in ('Debelina igle', 'Velikost igle') and re.fullmatch(r'(?:Nm\s?)?\d{2,3}(?:/\d{2,3})?', v, re.I):
        return 'Debelina igle', re.sub(r'^Nm\s?', '', v, flags=re.I)   # machine needle size Nm 70/80/90
    if g == 'Barva' and v.casefold() in ('transparentna', 'transparent', 'prozoren', 'prozorni'):
        return g, 'Prozorna'
    if g == 'Vrsta igle' and v.casefold() in ('usnje', 'za usnje', 'leather'):
        return g, 'Za usnje'
    return g, v
