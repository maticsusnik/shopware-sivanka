import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'sivanka-service-cta',
    label: 'sw-cms.blocks.sivanka.sivankaServiceCta.label',
    category: 'sivanka-storitve',
    component: 'sw-cms-block-sivanka-service-cta',
    previewComponent: 'sw-cms-preview-sivanka-service-cta',
    defaultConfig: {
        marginTop: '0px',
        marginBottom: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed',
    },
    slots: {
        intro: {
            type: 'sivanka-service-intro',
            default: {
                config: {
                    eyebrow: { source: 'static', value: '' },
                    headline: { source: 'static', value: 'Niste prepričani, kaj potrebujete?' },
                    lead: {
                        source: 'static',
                        value: 'Prinesite kos v trgovino. Pogledamo ga skupaj in povemo, kaj je mogoče popraviti in koliko bo trajalo.',
                    },
                    headlineLevel: { source: 'static', value: 'h2' },
                    variant: { source: 'static', value: 'cta' },
                },
            },
        },
        actions: {
            type: 'sivanka-service-actions',
            default: {
                config: {
                    primaryLabel: { source: 'static', value: 'Pokličite nas' },
                    primaryUrl: { source: 'static', value: '' },
                    secondaryLabel: { source: 'static', value: '' },
                    secondaryUrl: { source: 'static', value: '' },
                    newTab: { source: 'static', value: false },
                },
            },
        },
    },
});
