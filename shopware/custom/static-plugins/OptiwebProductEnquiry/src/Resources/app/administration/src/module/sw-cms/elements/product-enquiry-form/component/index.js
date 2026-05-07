import template from './sw-cms-el-product-enquiry-form.html.twig';
import './sw-cms-el-product-enquiry-form.scss';

const { Mixin } = Shopware;

Shopware.Component.register('sw-cms-el-product-enquiry-form', {
    template,

    mixins: [
        Mixin.getByName('cms-element'),
    ],

    created() {
        this.initElementConfig('product-enquiry-form');
    },
});
