import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'category-description-and-faq',
    label: 'Category description and faq',
    category: 'sivanka',
    component: 'sw-cms-block-category-description-and-faq',
    previewComponent: 'sw-cms-preview-category-description-and-faq',
    defaultConfig: {
        marginBottom: '0px',
        marginTop: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed'
    },
    slots: {
        headline: {
            type: 'text',
            default: {
                config: {
                    content: {
                        source: 'static',
                        value: `<h2>Category name</h2>`.trim(),
                    },
                },
            },
        },
        image: {
            type: 'image',
            default: {
                config: {
                    displayMode: {source: 'static', value: 'standard'},
                },
                data: {
                    media: {
                        value: '/administration/static/img/cms/preview_mountain_large.jpg',
                        source: 'default',
                    },
                },
            },
        },
        text: {
            type: 'text',
            default: {
                config: {
                    content: {
                        source: 'static',
                        value: `<p>Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. </p>`.trim(),
                    },
                },
            },
        },
        faq: {
            type: 'faq',
        }
    }
});
