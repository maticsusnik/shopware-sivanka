import './component';
import './preview';

const tile = (icon, title, text, meta) => ({
    type: 'sivanka-service-card',
    default: {
        config: {
            media: { source: 'static', value: null, entity: { name: 'media' } },
            icon: { source: 'static', value: icon },
            title: { source: 'static', value: title },
            text: { source: 'static', value: text },
            meta: { source: 'static', value: meta },
            url: { source: 'static', value: '' },
            linkLabel: { source: 'static', value: 'Več o storitvi' },
            variant: { source: 'static', value: 'tile' },
        },
    },
});

Shopware.Service('cmsService').registerCmsBlock({
    name: 'sivanka-home-services',
    label: 'sw-cms.blocks.sivanka.sivankaHomeServices.label',
    category: 'sivanka',
    component: 'sw-cms-block-sivanka-home-services',
    previewComponent: 'sw-cms-preview-sivanka-home-services',
    defaultConfig: {
        marginTop: '0px',
        marginBottom: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed',
    },
    slots: {
        heading: {
            type: 'sivanka-service-intro',
            default: {
                config: {
                    eyebrow: { source: 'static', value: '' },
                    headline: { source: 'static', value: 'Kaj naredimo za vas' },
                    lead: {
                        source: 'static',
                        value: 'Poleg preje, tkanin in galanterije v delavnici v Kranju opravljamo tudi šiviljske storitve.',
                    },
                    headlineLevel: { source: 'static', value: 'h2' },
                    variant: { source: 'static', value: 'home' },
                },
            },
        },
        cardOne: tile('ruler', 'Merjenje in svetovanje', 'Izmerimo kos in povemo, koliko blaga, preje ali galanterije potrebujete. Svetujemo tudi pri izbiri materiala.', 'Brezplačno · brez naročanja'),
        cardTwo: tile('needle', 'Šivanje po meri', 'Zavese, prevleke, posteljnina, vrečke in preprosta oblačila po vaših merah — iz našega ali vašega materiala.', '5–10 delovnih dni'),
        cardThree: tile('zipper', 'Menjava zadrg', 'Zamenjamo zadrge na jaknah, hlačah, krilih, torbah in nahrbtnikih. Če je zadrga cela, zamenjamo le drsnik.', '2–5 delovnih dni'),
        cardFour: tile('scissors', 'Popravila in krajšanje', 'Krajšamo hlače, krila in rokave, popravljamo šive, prišivamo gumbe in obnovimo obrabljene kose.', '2–4 delovni dnevi'),
        actions: {
            type: 'sivanka-service-actions',
            default: {
                config: {
                    primaryLabel: { source: 'static', value: '' },
                    primaryUrl: { source: 'static', value: '' },
                    secondaryLabel: { source: 'static', value: 'Vse storitve' },
                    secondaryUrl: { source: 'static', value: '' },
                    newTab: { source: 'static', value: false },
                },
            },
        },
    },
});
