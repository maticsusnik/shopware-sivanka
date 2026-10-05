import './component';
import './preview';
import './config';

Shopware.Service('cmsService').registerCmsElement({
    name: "title-content-button",
    label: "Title content button",
    component: 'sw-cms-el-title-content-button',
    configComponent: 'sw-cms-el-config-title-content-button',
    previewComponent: 'sw-cms-el-preview-title-content-button',
    removable: false,
    hidden: true,
    defaultConfig: {
        title: {
            source: 'static',
            value: {
                text: 'Lorem ipsum dolor sit amet'
            }
        },
        titleType: {
            source: 'static',
            value: 'h2'
        },
        content: {
            source: 'static',
            value: "<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed sed magna eu quam eleifend consequat.</p>"

        },
        button: {
            source: 'static',
            value: {
                type: "external",
                link: null,
                entity: null,
                entityId: null,
                text: "Button"
            }
        }

    }
});
