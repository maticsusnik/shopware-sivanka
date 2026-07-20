import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'sivanka-hero',
    label: 'Sivanka Hero',
    category: 'sivanka',
    component: 'sw-cms-block-sivanka-hero',
    previewComponent: 'sw-cms-preview-sivanka-hero',
    defaultConfig: {
        marginBottom: '0px',
        marginTop: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed'
    },
    slots: {
        hero: 'sivanka-hero'
    }
});
