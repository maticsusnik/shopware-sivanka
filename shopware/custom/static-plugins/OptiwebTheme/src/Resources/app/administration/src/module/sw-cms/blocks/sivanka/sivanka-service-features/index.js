import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'sivanka-service-features',
    label: 'sw-cms.blocks.sivanka.sivankaServiceFeatures.label',
    category: 'sivanka-storitve',
    component: 'sw-cms-block-sivanka-service-features',
    previewComponent: 'sw-cms-preview-sivanka-service-features',
    defaultConfig: {
        marginTop: '0px',
        marginBottom: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed',
    },
    slots: {
        heading: {
            type: 'text',
            default: {
                config: {
                    content: { source: 'static', value: '<h2>Kaj vključuje</h2>' },
                    verticalAlign: { source: 'static', value: null },
                },
            },
        },
        cardOne: {
            type: 'sivanka-icon-card',
            default: {
                config: {
                    icon: { source: 'static', value: 'ruler' },
                    title: { source: 'static', value: 'Merjenje na mestu' },
                    text: {
                        source: 'static',
                        value: 'Izmerimo dolžine, širine in obsege za zavese, prevleke in oblačila.',
                    },
                    variant: { source: 'static', value: 'card' },
                },
            },
        },
        cardTwo: {
            type: 'sivanka-icon-card',
            default: {
                config: {
                    icon: { source: 'static', value: 'calculator' },
                    title: { source: 'static', value: 'Izračun količine' },
                    text: {
                        source: 'static',
                        value: 'Povemo, koliko metrov blaga ali koliko klobčičev preje potrebujete.',
                    },
                    variant: { source: 'static', value: 'card' },
                },
            },
        },
        cardThree: {
            type: 'sivanka-icon-card',
            default: {
                config: {
                    icon: { source: 'static', value: 'fabric' },
                    title: { source: 'static', value: 'Izbira materiala' },
                    text: {
                        source: 'static',
                        value: 'Svetujemo pri teži, sestavi in vzdrževanju izbranega materiala.',
                    },
                    variant: { source: 'static', value: 'card' },
                },
            },
        },
    },
});
