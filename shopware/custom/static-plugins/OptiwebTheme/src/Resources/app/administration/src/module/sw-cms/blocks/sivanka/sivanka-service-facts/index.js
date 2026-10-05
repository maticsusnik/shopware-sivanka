import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'sivanka-service-facts',
    label: 'sw-cms.blocks.sivanka.sivankaServiceFacts.label',
    category: 'sivanka-storitve',
    component: 'sw-cms-block-sivanka-service-facts',
    previewComponent: 'sw-cms-preview-sivanka-service-facts',
    defaultConfig: {
        marginTop: '0px',
        marginBottom: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed',
    },
    slots: {
        factOne: {
            type: 'sivanka-icon-card',
            default: {
                config: {
                    icon: { source: 'static', value: 'clock' },
                    title: { source: 'static', value: 'Brez naročanja' },
                    text: { source: 'static', value: 'Pridite v odpiralnem času' },
                    variant: { source: 'static', value: 'fact' },
                },
            },
        },
        factTwo: {
            type: 'sivanka-icon-card',
            default: {
                config: {
                    icon: { source: 'static', value: 'ruler' },
                    title: { source: 'static', value: 'Brezplačno' },
                    text: { source: 'static', value: 'Merjenje in svetovanje' },
                    variant: { source: 'static', value: 'fact' },
                },
            },
        },
        factThree: {
            type: 'sivanka-icon-card',
            default: {
                config: {
                    icon: { source: 'static', value: 'home' },
                    title: { source: 'static', value: 'V trgovini' },
                    text: { source: 'static', value: 'Cesta Staneta Žagarja 32, Kranj' },
                    variant: { source: 'static', value: 'fact' },
                },
            },
        },
    },
});
