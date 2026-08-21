import template from './sw-cms-el-sivanka-icon-card.html.twig';
import './sw-cms-el-sivanka-icon-card.scss';
import { SIVANKA_SERVICE_ICON_KEYS, sivankaIconSnippet } from '../../sivanka-service-icons';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-sivanka-icon-card', {
    template,

    mixins: [Mixin.getByName('cms-element')],

    computed: {
        iconLabel() {
            const key = this.element?.config?.icon?.value;

            return SIVANKA_SERVICE_ICON_KEYS.includes(key) ? this.$tc(sivankaIconSnippet(key)) : '';
        },
    },

    created() {
        this.initElementConfig('sivanka-icon-card');
    },
});
