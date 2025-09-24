import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'image-repeater',
    label: 'Image repeater',
    category: 'sivanka',
    component: 'sw-cms-block-image-repeater',
    previewComponent: 'sw-cms-preview-image-repeater',
    defaultConfig: {
        marginBottom: '0px',
        marginTop: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed'
    },
    slots: {
        tabs: 'image-tab'
    }
});
