import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'img-text',
    label: 'Image + text + button',
    category: 'sivanka',
    component: 'sw-cms-block-img-text',
    previewComponent: 'sw-cms-preview-img-text',
    defaultConfig: {
        marginBottom: '0px',
        marginTop: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed',
        customFields: {
            layout: 'text-right-over-image'
        }
    },
    slots: {
        image: {
            type: 'image',
            default: {
                config: {
                    displayMode: {
                        source: 'static',
                        value: 'stretch'
                    },
                    horizontalAlign: {
                        source: 'static',
                        value: 'flex-start'
                    }
                },
                data: {
                    media: {
                        value: '/administration/static/img/cms/preview_plant_large.jpg',
                        source: 'default',
                    },
                },
            },
        },
        content: 'title-content-button'
    }
});
