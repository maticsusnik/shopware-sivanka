import './component';
import './config';
import './preview';

Shopware.Service('cmsService').registerCmsElement({
    name: 'sivanka-product-slider',
    label: 'Sivanka product slider',
    component: 'sw-cms-el-sivanka-product-slider',
    configComponent: 'sw-cms-el-config-sivanka-product-slider',
    previewComponent: 'sw-cms-el-preview-sivanka-product-slider',
    removable: false,
    hidden: true,
    defaultConfig: {
        title: { 
            source: 'static', 
            value: { text: 'Products' } 
        },
        selectionMode: { 
            source: 'static', 
            value: 'manual' 
        },
        productIds: { 
            source: 'static', 
            value: [],
            entity: { name: 'product' }
        },
        parentCategoryId: { 
            source: 'static', 
            value: '',
            entity: { name: 'category' }
        },
        image: {
            source: 'static',
            value: null,
            required: false,
            entity: { name: 'media' },
        }
    }
});




