import template from './sw-cms-el-config-sivanka-step-card.html.twig';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-config-sivanka-step-card', {
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
