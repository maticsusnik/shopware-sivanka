import template from './sw-cms-el-sivanka-service-card.html.twig';
import './sw-cms-el-sivanka-service-card.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-sivanka-service-card', {
    template,

    mixins: [Mixin.getByName('cms-element')],

    computed: {
        mediaUrl() {
            return this.element?.data?.media?.url ?? null;
        },
    },

    created() {
        this.initElementConfig('sivanka-service-card');
    },
});
