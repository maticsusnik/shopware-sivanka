import template from './sw-cms-el-sivanka-hero.html.twig';
import './sw-cms-el-sivanka-hero.scss';

const { Component, Mixin } = Shopware;
Component.register('sw-cms-el-sivanka-hero', {
    template,
    mixins: [Mixin.getByName('cms-element')],
    created() { this.initElementConfig('sivanka-hero'); }
});
