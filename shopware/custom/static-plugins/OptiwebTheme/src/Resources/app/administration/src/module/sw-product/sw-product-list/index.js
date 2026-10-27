import template from './sw-product-list.html.twig';

const { Component } = Shopware;

/**
 * "Make variants" and "Split variants" next to "Bulk edit" and "Delete" when
 * products are selected.
 */
Component.override('sw-product-list', {
    template,

    data() {
        return {
            showVariantBuilder: false,
            showVariantSplit: false,
        };
    },

    methods: {
        onVariantsCreated(parentId) {
            this.showVariantBuilder = false;
            this.$refs.swProductGrid?.resetSelection?.();
            this.$router.push({ name: 'sw.product.detail.variants', params: { id: parentId } });
        },

        onVariantsSplit() {
            this.showVariantSplit = false;
            this.$refs.swProductGrid?.resetSelection?.();
            this.getList();
        },
    },
});
