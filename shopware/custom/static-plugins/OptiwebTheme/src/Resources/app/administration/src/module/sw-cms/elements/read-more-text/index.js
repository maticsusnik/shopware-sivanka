import './component';
import './preview';
import './config';

Shopware.Service('cmsService').registerCmsElement({
    name: "read-more-text",
    label: "Read more text",
    component: 'sw-cms-el-read-more-text',
    configComponent: 'sw-cms-el-config-read-more-text',
    previewComponent: 'sw-cms-el-preview-read-more-text',
    defaultConfig: {
        content: {
            source: 'static',
            value: "<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed sed magna eu quam eleifend consequat.</p>"
        }
    }
});
