import './component';
import './preview';
import './config';

Shopware.Service('cmsService').registerCmsElement({
    name: "category-selection",
    label: "Category selection",
    component: 'sw-cms-el-category-selection',
    configComponent: 'sw-cms-el-config-category-selection',
    previewComponent: 'sw-cms-el-preview-category-selection',
    removable: false,
    hidden: true,
    defaultConfig: {
        title: {
            source: 'static',
            value: {
                text: 'Category slider title'
            }
        },
        category: {
            source: 'static',
            value: '',
            required: true,
            entity: {
                name: 'category',
                criteria: new Shopware.Data.Criteria(1, 100),
            },
        },
    }
});
