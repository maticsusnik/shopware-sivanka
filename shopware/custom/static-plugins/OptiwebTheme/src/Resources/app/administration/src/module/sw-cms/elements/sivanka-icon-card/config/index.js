import template from './sw-cms-el-config-sivanka-icon-card.html.twig';
import { sivankaIconOptions } from '../../sivanka-service-icons';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-config-sivanka-icon-card', {
    template,

    emits: ['element-update'],

    mixins: [Mixin.getByName('cms-element')],

    computed: {
        iconOptions() {
            return sivankaIconOptions(this.$tc);
        },

        variantOptions() {
            return [
                { value: 'card', label: this.$tc('sw-cms.elements.sivankaIconCard.config.label.variantCard') },
                { value: 'fact', label: this.$tc('sw-cms.elements.sivankaIconCard.config.label.variantFact') },
            ];
        },
    },

    created() {
        this.initElementConfig('sivanka-icon-card');
    },

    methods: {
        onChange() {
            this.$emit('element-update', this.element);
        },
    },
});
