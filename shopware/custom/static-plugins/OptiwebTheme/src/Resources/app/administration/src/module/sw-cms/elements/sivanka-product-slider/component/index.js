import template from './sw-cms-el-sivanka-product-slider.html.twig';
import './sw-cms-el-sivanka-product-slider.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-sivanka-product-slider', {
    template,
    mixins: [Mixin.getByName('cms-element')],
    created() {
        this.initElementConfig('sivanka-product-slider');
    }
});




