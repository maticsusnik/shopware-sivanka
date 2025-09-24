import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'main-slider',
    label: 'Main slider',
    category: 'sivanka',
    component: 'sw-cms-block-main-slider',
    previewComponent: 'sw-cms-preview-main-slider',
    defaultConfig: {
        marginBottom: '0px',
        marginTop: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed'
    },
    slots: {
        tabs: 'main-slider'
    }
});
