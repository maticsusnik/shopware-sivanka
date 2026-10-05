import './page/optiweb-product-enquiry-list';
import './page/optiweb-product-enquiry-detail';

const { Module } = Shopware;

Module.register('optiweb-product-enquiry', {
    type: 'plugin',
    name: 'optiwebProductEnquiry.admin.module.name',
    title: 'optiwebProductEnquiry.admin.module.title',
    description: 'optiwebProductEnquiry.admin.module.description',
    color: '#3498db',
    icon: 'regular-envelope',
    snippets: {
        'en-GB': () => import('./snippet/en-GB.json'),
    },
    routes: {
        list: {
            component: 'optiweb-product-enquiry-list',
            path: 'list',
        },
        detail: {
            component: 'optiweb-product-enquiry-detail',
            path: 'detail/:id',
            props: {
                default(route) {
                    return { enquiryId: route.params.id };
                },
            },
        },
    },
    navigation: [
        {
            label: 'optiwebProductEnquiry.admin.module.title',
            color: '#3498db',
            path: 'optiweb.product.enquiry.list',
            icon: 'regular-envelope',
            parent: 'sw-marketing',
            position: 100,
        },
    ],
});
