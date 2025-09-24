import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'documents',
    label: 'Documents',
    category: 'sivanka',
    component: 'sw-cms-block-documents',
    previewComponent: 'sw-cms-preview-documents',
    defaultConfig: {
        marginBottom: '0px',
        marginTop: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed',
    },
    slots: {
        content: 'documents-upload'
    }
});
