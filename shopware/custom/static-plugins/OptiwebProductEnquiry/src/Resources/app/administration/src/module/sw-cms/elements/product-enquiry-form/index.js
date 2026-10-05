import './component';
import './config';
import './preview';

Shopware.Service('cmsService').registerCmsElement({
    name: 'product-enquiry-form',
    label: 'sw-cms.elements.productEnquiryForm.label',
    component: 'sw-cms-el-product-enquiry-form',
    configComponent: 'sw-cms-el-config-product-enquiry-form',
    previewComponent: 'sw-cms-el-preview-product-enquiry-form',
    defaultConfig: {
        type: { source: 'static', value: 'product-enquiry' },
        title: { source: 'static', value: '' },
        subtitle: { source: 'static', value: '' },
        mailReceiver: { source: 'static', value: [] },
        confirmationText: { source: 'static', value: '' },
    },
});
