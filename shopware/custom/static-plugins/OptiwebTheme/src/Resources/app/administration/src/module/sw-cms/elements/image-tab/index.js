import './component';
import './preview';
import './config';

Shopware.Service('cmsService').registerCmsElement({
    name: "image-tab",
    label: "Image tab settings",
    component: 'sw-cms-el-image-tab',
    configComponent: 'sw-cms-el-config-image-tab',
    previewComponent: 'sw-cms-el-preview-image-tab',
    removable:false,
    hidden:true,
    defaultConfig: {
        tabs: {
            source: 'static',
            value: []
        },
        title: {
            source: 'static',
            value: {
                text: 'Lorem ipsum dolor sit amet'
            }
        },
        update: {
            source: 'static',
            value: ''
        }
    }
});
