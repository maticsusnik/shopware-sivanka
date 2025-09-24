import './component';
import './config';
import './preview';

Shopware.Service('cmsService').registerCmsElement({
    name: 'catalogs',
    label: 'Catalogs',
    component: 'sw-cms-el-catalogs',
    configComponent: 'sw-cms-el-config-catalogs',
    previewComponent: 'sw-cms-el-preview-catalogs',
    removable:false,
    hidden:true,
    defaultConfig: {
        catalogs: {
            source: 'static',
            value: []
        }
    }
});
