import './component';
import './config';
import './preview';

Shopware.Service('cmsService').registerCmsElement({
    name: 'banners',
    label: 'Banners',
    component: 'sw-cms-el-banners',
    configComponent: 'sw-cms-el-config-banners',
    previewComponent: 'sw-cms-el-preview-banners',
    removable: false,
    hidden: true,
    defaultConfig: {
        sectionTitle: {
            source: 'static',
            value: ''
        },
        perRow: {
            source: 'static',
            value: 3
        },
        banners: {
            source: 'static',
            value: []
        }
    }
});




