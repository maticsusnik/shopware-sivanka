import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'banners',
    label: 'Banners',
    category: 'sivanka',
    component: 'sw-cms-block-banners',
    previewComponent: 'sw-cms-preview-banners',
    defaultConfig: {
        marginBottom: '0px',
        marginTop: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed'
    },
    slots: {
        banners: 'banners'
    }
});




