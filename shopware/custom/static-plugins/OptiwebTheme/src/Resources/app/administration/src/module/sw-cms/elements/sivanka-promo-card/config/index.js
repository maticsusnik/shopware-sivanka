import template from './sw-cms-el-config-sivanka-promo-card.html.twig';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-config-sivanka-promo-card', {
    template,

    emits: ['element-update'],

    mixins: [Mixin.getByName('cms-element')],

    computed: {
        focusOptions() {
            return [
                { value: 'left', label: this.$tc('sw-cms.elements.sivankaPromoCard.config.label.focusLeft') },
                { value: 'center', label: this.$tc('sw-cms.elements.sivankaPromoCard.config.label.focusCenter') },
                { value: 'right', label: this.$tc('sw-cms.elements.sivankaPromoCard.config.label.focusRight') },
            ];
        },
    },

    created() {
        this.initElementConfig('sivanka-promo-card');
    },

    methods: {
        onChange() {
            this.$emit('element-update', this.element);
        },
    },
});
