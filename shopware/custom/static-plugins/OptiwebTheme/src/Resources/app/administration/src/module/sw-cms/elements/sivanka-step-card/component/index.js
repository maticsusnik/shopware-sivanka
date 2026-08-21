import template from './sw-cms-el-sivanka-step-card.html.twig';
import './sw-cms-el-sivanka-step-card.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-sivanka-step-card', {
    template,

    mixins: [Mixin.getByName('cms-element')],

    created() {
        this.initElementConfig('sivanka-step-card');
    },
});
