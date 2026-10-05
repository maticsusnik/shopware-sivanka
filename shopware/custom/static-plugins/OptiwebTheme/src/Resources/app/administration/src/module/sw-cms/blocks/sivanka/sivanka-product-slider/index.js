import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'sivanka-product-slider',
    label: 'Sivanka product slider',
    category: 'sivanka',
    component: 'sw-cms-block-sivanka-product-slider',
    previewComponent: 'sw-cms-preview-sivanka-product-slider',
    defaultConfig: {
        marginBottom: '0px',
        marginTop: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed'
    },
    slots: {
        products: 'sivanka-product-slider'
    }
});




