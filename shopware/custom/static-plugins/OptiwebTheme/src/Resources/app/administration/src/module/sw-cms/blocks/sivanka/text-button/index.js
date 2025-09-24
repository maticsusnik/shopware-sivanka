import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'text-button',
    label: 'Text + button',
    category: 'sivanka',
    component: 'sw-cms-block-text-button',
    previewComponent: 'sw-cms-preview-text-button',
    defaultConfig: {
        marginBottom: '0px',
        marginTop: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed',
    },
    slots: {
        content: 'title-content-button'
    }
});
