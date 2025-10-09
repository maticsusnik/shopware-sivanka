import template from './sw-cms-el-preview-banners.html.twig';
import './sw-cms-el-preview-banners.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-preview-banners', {
    template,

    mixins: [
        Mixin.getByName('cms-element')
    ]
});




