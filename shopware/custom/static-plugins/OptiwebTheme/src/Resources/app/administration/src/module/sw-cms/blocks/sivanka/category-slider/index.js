import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'category-slider',
    label: 'Categories slider',
    category: 'sivanka',
    component: 'sw-cms-block-category-slider',
    previewComponent: 'sw-cms-preview-category-slider',
    defaultConfig: {
        marginBottom: '0px',
        marginTop: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'full-width'
    },
    slots: {
        category: 'category-selection'
    }
});
