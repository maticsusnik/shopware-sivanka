import './component';
import './preview';

const { Application } = Shopware;

Application.getContainer('service').cmsService.registerCmsBlock({
    name: 'sivanka-category-navigation',
    label: 'Category Navigation',
    category: 'sivanka',
    component: 'sw-cms-block-sivanka-category-navigation',
    previewComponent: 'sw-cms-block-preview-sivanka-category-navigation',
    defaultConfig: {
        marginBottom: '20px',
        marginTop: '20px',
        marginLeft: '20px',
        marginRight: '20px',
        sizingMode: 'boxed',
    },
    slots: {
        content: 'sivanka-category-navigation',
    },
});

