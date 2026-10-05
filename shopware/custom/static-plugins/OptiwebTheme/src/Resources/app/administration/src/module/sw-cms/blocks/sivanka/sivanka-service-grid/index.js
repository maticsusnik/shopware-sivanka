import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'sivanka-service-grid',
    label: 'sw-cms.blocks.sivanka.sivankaServiceGrid.label',
    category: 'sivanka-storitve',
    component: 'sw-cms-block-sivanka-service-grid',
    previewComponent: 'sw-cms-preview-sivanka-service-grid',
    defaultConfig: {
        marginTop: '0px',
        marginBottom: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed',
    },
    slots: {
        cardOne: {
            type: 'sivanka-service-card',
            default: {
                config: {
                    icon: { source: 'static', value: 'ruler' },
                    title: { source: 'static', value: 'Merjenje in svetovanje' },
                    text: {
                        source: 'static',
                        value: 'Izmerimo kos in povemo, koliko blaga, preje ali galanterije potrebujete. Svetujemo tudi pri izbiri materiala.',
                    },
                    url: { source: 'static', value: '' },
                    linkLabel: { source: 'static', value: 'Več' },
                    variant: { source: 'static', value: 'wide' },
                },
            },
        },
        cardTwo: {
            type: 'sivanka-service-card',
            default: {
                config: {
                    icon: { source: 'static', value: 'needle' },
                    title: { source: 'static', value: 'Šivanje po meri' },
                    text: {
                        source: 'static',
                        value: 'Zavese, prevleke, posteljnina, vrečke in preprosta oblačila po vaših merah — iz našega ali vašega materiala.',
                    },
                    url: { source: 'static', value: '' },
                    linkLabel: { source: 'static', value: 'Več' },
                    variant: { source: 'static', value: 'wide' },
                },
            },
        },
        cardThree: {
            type: 'sivanka-service-card',
            default: {
                config: {
                    icon: { source: 'static', value: 'zipper' },
                    title: { source: 'static', value: 'Menjava zadrg' },
                    text: {
                        source: 'static',
                        value: 'Zamenjamo zadrge na jaknah, hlačah, krilih, torbah in nahrbtnikih. Če je zadrga cela, zamenjamo le drsnik.',
                    },
                    url: { source: 'static', value: '' },
                    linkLabel: { source: 'static', value: 'Več' },
                    variant: { source: 'static', value: 'wide' },
                },
            },
        },
        cardFour: {
            type: 'sivanka-service-card',
            default: {
                config: {
                    icon: { source: 'static', value: 'scissors' },
                    title: { source: 'static', value: 'Popravila in krajšanje' },
                    text: {
                        source: 'static',
                        value: 'Krajšamo hlače, krila in rokave, popravljamo šive, prišivamo gumbe in obnovimo obrabljene kose.',
                    },
                    url: { source: 'static', value: '' },
                    linkLabel: { source: 'static', value: 'Več' },
                    variant: { source: 'static', value: 'wide' },
                },
            },
        },
    },
});
