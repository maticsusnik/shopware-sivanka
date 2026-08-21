import template from './sw-cms-el-preview-sivanka-hero.html.twig';
import './sw-cms-el-preview-sivanka-hero.scss';

const { Component, Mixin } = Shopware;
Component.register('sw-cms-el-preview-sivanka-hero', {
    template,
    mixins: [Mixin.getByName('cms-element')]
});
