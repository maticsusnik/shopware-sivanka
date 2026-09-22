import template from './sw-cms-el-sivanka-product-slider.html.twig';
import './sw-cms-el-sivanka-product-slider.scss';
import { fillNestedConfigDefaults } from '../../../element-config';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-sivanka-product-slider', {
    template,
    mixins: [Mixin.getByName('cms-element')],
    created() {
        this.initElementConfig();
        fillNestedConfigDefaults(this);
    }
});




