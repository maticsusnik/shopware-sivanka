import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'sivanka-faq',
    label: 'sw-cms.blocks.sivanka.sivankaFaq.label',
    category: 'sivanka',
    component: 'sw-cms-block-sivanka-faq',
    previewComponent: 'sw-cms-preview-sivanka-faq',
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
                    headline: { source: 'static', value: 'Pogosta vprašanja' },
                    lead: { source: 'static', value: '' },
                    headlineLevel: { source: 'static', value: 'h2' },
                    variant: { source: 'static', value: 'home' },
                },
            },
        },
        image: {
            type: 'image',
            default: {
                config: {
                    displayMode: { source: 'static', value: 'cover' },
                    minHeight: { source: 'static', value: '' },
                },
            },
        },
        text: {
            type: 'text',
            default: {
                config: {
                    content: {
                        source: 'static',
                        value: 'Trgovina Šivanka v Kranju že vrsto let ponuja preje, tkanine, galanterijo in šiviljski pribor, poleg tega pa v delavnici opravljamo popravila in šivanje po meri. Spodaj so odgovori na vprašanja, ki jih slišimo najpogosteje. Če vašega ni med njimi, nas pokličite ali obiščite v trgovini.',
                    },
                },
            },
        },
        faq: {
            type: 'faq',
            default: {
                config: {
                    faq: {
                        source: 'static',
                        value: [
                            { question: 'Kje vas najdemo in kdaj ste odprti?', answer: '' },
                            { question: 'Katere šiviljske storitve opravljate?', answer: '' },
                            { question: 'Lahko izdelek iz spletne trgovine prevzamem v trgovini?', answer: '' },
                        ],
                    },
                },
            },
        },
    },
});
