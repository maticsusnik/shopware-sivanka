import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'sivanka-service-links',
    label: 'sw-cms.blocks.sivanka.sivankaServiceLinks.label',
    category: 'sivanka-storitve',
    component: 'sw-cms-block-sivanka-service-links',
    previewComponent: 'sw-cms-preview-sivanka-service-links',
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
                    content: { source: 'static', value: '<h2>Druge storitve</h2>' },
                    verticalAlign: { source: 'static', value: null },
                },
            },
        },
        linkOne: {
            type: 'sivanka-service-card',
            default: {
                config: {
                    icon: { source: 'static', value: 'needle' },
                    title: { source: 'static', value: 'Šivanje po meri' },
                    text: { source: 'static', value: '' },
                    url: { source: 'static', value: '' },
                    linkLabel: { source: 'static', value: 'Več' },
                    variant: { source: 'static', value: 'compact' },
                },
            },
        },
        linkTwo: {
            type: 'sivanka-service-card',
            default: {
                config: {
                    icon: { source: 'static', value: 'zipper' },
                    title: { source: 'static', value: 'Menjava zadrg' },
                    text: { source: 'static', value: '' },
                    url: { source: 'static', value: '' },
                    linkLabel: { source: 'static', value: 'Več' },
                    variant: { source: 'static', value: 'compact' },
                },
            },
        },
        linkThree: {
            type: 'sivanka-service-card',
            default: {
                config: {
                    icon: { source: 'static', value: 'scissors' },
                    title: { source: 'static', value: 'Popravila in krajšanje' },
                    text: { source: 'static', value: '' },
                    url: { source: 'static', value: '' },
                    linkLabel: { source: 'static', value: 'Več' },
                    variant: { source: 'static', value: 'compact' },
                },
            },
        },
    },
});
