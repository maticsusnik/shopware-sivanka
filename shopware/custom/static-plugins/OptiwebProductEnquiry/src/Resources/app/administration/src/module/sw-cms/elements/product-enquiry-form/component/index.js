import template from './sw-cms-el-product-enquiry-form.html.twig';

Shopware.Component.register('sw-cms-el-product-enquiry-form', {
    template,

    mixins: [
        'cms-element'
    ],

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.initElementConfig('product-enquiry-form');
        }
    }
});
