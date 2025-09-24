import './component';
import './preview';
import './config';

Shopware.Service('cmsService').registerCmsElement({
    name: "documents-upload",
    label: "Documents upload",
    component: 'sw-cms-el-documents-upload',
    configComponent: 'sw-cms-el-config-documents-upload',
    previewComponent: 'sw-cms-el-preview-documents-upload',
    removable: false,
    hidden: true,
    defaultConfig: {
        title: {
            source: 'static',
            value: 'Documents title'
        },
        documents: {
            source: 'static',
            value: []
        },
        update: {
            source: 'static',
            value: ''
        }
    }
});
