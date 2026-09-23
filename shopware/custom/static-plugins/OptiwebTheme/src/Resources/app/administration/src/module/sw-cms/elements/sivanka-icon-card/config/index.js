import template from './sw-cms-el-config-sivanka-icon-card.html.twig';
import { sivankaIconOptions } from '../../sivanka-service-icons';
import { upgradePlainTextToHtml } from '../../../element-config';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-config-sivanka-icon-card', {
    template,

    emits: ['element-update'],

    mixins: [Mixin.getByName('cms-element')],

    computed: {
        iconOptions() {
            return sivankaIconOptions(this.$t);
        },

        variantOptions() {
            return [
                { value: 'card', label: this.$t('sw-cms.elements.sivankaIconCard.config.label.variantCard') },
                { value: 'fact', label: this.$t('sw-cms.elements.sivankaIconCard.config.label.variantFact') },
                { value: 'medallion', label: this.$t('sw-cms.elements.sivankaIconCard.config.label.variantMedallion') },
            ];
        },
    },

    created() {
        this.initElementConfig();
        upgradePlainTextToHtml(this.element.config.text);
    },

    methods: {
        onChange() {
            this.$emit('element-update', this.element);
        },
    },
});
