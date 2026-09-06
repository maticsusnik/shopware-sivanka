import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'sivanka-hero',
    label: 'sw-cms.blocks.sivanka.sivankaHero.label',
    category: 'sivanka',
    component: 'sw-cms-block-sivanka-hero',
    previewComponent: 'sw-cms-preview-sivanka-hero',
    defaultConfig: {
        marginBottom: '0px',
        marginTop: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'full_width'
    },
    slots: {
        hero: 'sivanka-hero'
    }
});
