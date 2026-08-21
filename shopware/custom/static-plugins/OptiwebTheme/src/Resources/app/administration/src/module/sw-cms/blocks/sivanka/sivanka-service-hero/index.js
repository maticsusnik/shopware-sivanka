import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'sivanka-service-hero',
    label: 'sw-cms.blocks.sivanka.sivankaServiceHero.label',
    category: 'sivanka-storitve',
    component: 'sw-cms-block-sivanka-service-hero',
    previewComponent: 'sw-cms-preview-sivanka-service-hero',
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
                    eyebrow: { source: 'static', value: 'Storitve' },
                    headline: { source: 'static', value: 'Merjenje in svetovanje' },
                    lead: {
                        source: 'static',
                        value: 'V trgovini vam izmerimo kos in svetujemo, koliko materiala potrebujete za svoj projekt.',
                    },
                    headlineLevel: { source: 'static', value: 'h1' },
                    variant: { source: 'static', value: 'hero' },
                },
            },
        },
        actions: {
            type: 'sivanka-service-actions',
            default: {
                config: {
                    primaryLabel: { source: 'static', value: 'Obiščite trgovino' },
                    primaryUrl: { source: 'static', value: '' },
                    secondaryLabel: { source: 'static', value: 'Pokličite nas' },
                    secondaryUrl: { source: 'static', value: '' },
                    newTab: { source: 'static', value: false },
                },
            },
        },
        image: {
            type: 'image',
            default: {
                config: {
                    // The theme SCSS crops the hero image to 4:3, so keep the core
                    // element in "standard" mode and let CSS do the object-fit.
                    displayMode: { source: 'static', value: 'standard' },
                },
            },
        },
    },
});
