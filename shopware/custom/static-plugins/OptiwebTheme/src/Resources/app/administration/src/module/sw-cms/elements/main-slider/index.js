import './component';
import './preview';
import './config';

Shopware.Service('cmsService').registerCmsElement({
    name: "main-slider",
    label: "Main slider settings",
    component: 'sw-cms-el-main-slider',
    configComponent: 'sw-cms-el-config-main-slider',
    previewComponent: 'sw-cms-el-preview-main-slider',
    removable:false,
    hidden:true,
    defaultConfig: {
        slides: {
            source: 'static',
            value: []
        },
        update: {
            source: 'static',
            value: ''
        }
    }
});
