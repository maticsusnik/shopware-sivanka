import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'sivanka-service-steps',
    label: 'sw-cms.blocks.sivanka.sivankaServiceSteps.label',
    category: 'sivanka-storitve',
    component: 'sw-cms-block-sivanka-service-steps',
    previewComponent: 'sw-cms-preview-sivanka-service-steps',
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
                    content: { source: 'static', value: '<h2>Kako poteka</h2>' },
                    verticalAlign: { source: 'static', value: null },
                },
            },
        },
        stepOne: {
            type: 'sivanka-step-card',
            default: {
                config: {
                    number: { source: 'static', value: '1' },
                    title: { source: 'static', value: 'Pridete v trgovino' },
                    text: {
                        source: 'static',
                        value: 'S seboj prinesite kos, ki ga želite ponoviti, ali že izmerjene mere.',
                    },
                },
            },
        },
        stepTwo: {
            type: 'sivanka-step-card',
            default: {
                config: {
                    number: { source: 'static', value: '2' },
                    title: { source: 'static', value: 'Skupaj izmerimo' },
                    text: {
                        source: 'static',
                        value: 'Mere zapišemo in preverimo, kako se obnese na izbranem materialu.',
                    },
                },
            },
        },
        stepThree: {
            type: 'sivanka-step-card',
            default: {
                config: {
                    number: { source: 'static', value: '3' },
                    title: { source: 'static', value: 'Pripravimo material' },
                    text: {
                        source: 'static',
                        value: 'Blago odrežemo na želeno dolžino in dodamo potrebno galanterijo.',
                    },
                },
            },
        },
    },
});
