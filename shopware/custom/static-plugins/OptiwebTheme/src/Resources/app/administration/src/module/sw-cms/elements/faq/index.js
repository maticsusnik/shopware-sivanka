import './component';
import './preview';
import './config';

Shopware.Service('cmsService').registerCmsElement({
    name: "faq",
    label: "Faq",
    component: 'sw-cms-el-faq',
    configComponent: 'sw-cms-el-config-faq',
    previewComponent: 'sw-cms-el-preview-faq',
    defaultConfig: {
        faq: {
            source: 'static',
            value: []
        }
    }
});
