import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'catalogs',
    label: 'Catalogs',
    category: 'sivanka',
    component: 'sw-cms-block-catalogs',
    previewComponent: 'sw-cms-preview-catalogs',
    defaultConfig: {
        marginBottom: '0',
        marginTop: '0',
        marginLeft: '0',
        marginRight: '0',
        sizingMode: 'boxed'
    },
    slots: {
        catalogs: 'catalogs'
    }
});
