import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'read-more',
    label: 'Description (with read more button)',
    category: 'sivanka',
    component: 'sw-cms-block-read-more',
    previewComponent: 'sw-cms-preview-read-more',
    defaultConfig: {
        marginBottom: '0px',
        marginTop: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed',
    },
    slots: {
        content: 'read-more-text'
    }
});
