import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'product-enquiry-form',
        label: 'Product Enquiry Form',
    category: 'form',
    component: 'sw-cms-block-product-enquiry-form',
    previewComponent: 'sw-cms-preview-product-enquiry-form',
    defaultConfig: {
        marginBottom: '0px',
        marginTop: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed'
    },
    slots: {
        content: {
            type: 'product-enquiry-form'
        }
    }
});
