import './component';
import './preview';
import './config';

Shopware.Service('cmsService').registerCmsElement({
    name: 'category-selection',
    label: 'Category selection',
    component: 'sw-cms-el-category-selection',
    configComponent: 'sw-cms-el-config-category-selection',
    previewComponent: 'sw-cms-el-preview-category-selection',
    removable: false,
    hidden: true,
    defaultConfig: {
        title: {
            source: 'static',
            value: { text: 'Category slider title' },
        },

        // keep it as string; UI maps it to 'single'|'multiple'
        selectionMode: {
            source: 'static',
            value: 'single',
        },

        parentCategoryId: {
            source: 'static',
            value: '',
            required: false,
            entity: { name: 'category' }, // criteria only at runtime
        },

        categories: {
            source: 'static',
            value: [],
            required: false,
            entity: { name: 'category' }, // criteria only at runtime
        },

        image: {
            source: 'static',
            value: null,
            required: false,
            entity: { name: 'media' },
        },
    },
});
