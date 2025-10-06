import template from './sw-cms-el-banners.html.twig';
import './sw-cms-el-banners.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-banners', {
    template,

    mixins: [
        Mixin.getByName('cms-element')
    ],

    created() {
        this.initElementConfig('banners');
    }
});




