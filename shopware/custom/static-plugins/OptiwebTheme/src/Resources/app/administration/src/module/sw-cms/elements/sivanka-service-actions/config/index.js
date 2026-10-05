import template from './sw-cms-el-config-sivanka-service-actions.html.twig';
import './sw-cms-el-config-sivanka-service-actions.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-config-sivanka-service-actions', {
    template,

    emits: ['element-update'],

    mixins: [Mixin.getByName('cms-element')],

    created() {
        this.initElementConfig();
    },

    methods: {
        onChange() {
            this.$emit('element-update', this.element);
        },
    },
});
