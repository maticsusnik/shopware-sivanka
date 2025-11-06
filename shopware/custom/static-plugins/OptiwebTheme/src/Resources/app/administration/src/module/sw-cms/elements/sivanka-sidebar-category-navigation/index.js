import './component';
import './config';
import './preview';

Shopware.Service('cmsService').registerCmsElement({
    name: 'sivanka-category-navigation',
    label: 'Sidebar Category Navigation',
    component: 'sw-cms-el-sivanka-category-navigation',
    configComponent: 'sw-cms-el-config-sivanka-category-navigation',
    previewComponent: 'sw-cms-el-preview-sivanka-category-navigation',
    defaultConfig: {
        parentCategory: {
            source: 'static',
            value: null,
            entity: {
                name: 'category'
            }
        },
        showParentCategory: {
            source: 'static',
            value: 'no'
        }
    }
});

