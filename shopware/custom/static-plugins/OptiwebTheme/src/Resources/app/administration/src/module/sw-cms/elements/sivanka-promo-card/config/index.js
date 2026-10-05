import template from './sw-cms-el-config-sivanka-promo-card.html.twig';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-config-sivanka-promo-card', {
    template,

    emits: ['element-update'],

    mixins: [Mixin.getByName('cms-element')],

    computed: {
        focusOptions() {
            return [
                { value: 'left', label: this.$t('sw-cms.elements.sivankaPromoCard.config.label.focusLeft') },
                { value: 'center', label: this.$t('sw-cms.elements.sivankaPromoCard.config.label.focusCenter') },
                { value: 'right', label: this.$t('sw-cms.elements.sivankaPromoCard.config.label.focusRight') },
            ];
        },
    },

    created() {
        this.initElementConfig();
    },

    methods: {
        onChange() {
            this.$emit('element-update', this.element);
        },
    },
});
