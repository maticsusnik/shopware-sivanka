import template from './sw-cms-el-sivanka-service-actions.html.twig';
import './sw-cms-el-sivanka-service-actions.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-sivanka-service-actions', {
    template,

    mixins: [Mixin.getByName('cms-element')],

    created() {
        this.initElementConfig('sivanka-service-actions');
    },
});
