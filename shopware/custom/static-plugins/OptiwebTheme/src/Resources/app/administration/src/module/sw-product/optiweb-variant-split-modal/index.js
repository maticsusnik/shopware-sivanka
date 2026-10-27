import template from './optiweb-variant-split-modal.html.twig';

const { Component, Mixin } = Shopware;

/**
 * Turns every variant of the selected parents back into a standalone product
 * (VariantSplitter); products without variants in the selection are listed but
 * left alone.
 */
Component.register('optiweb-variant-split-modal', {
    template,

    inject: ['syncService'],

    emits: ['modal-close', 'variants-split'],

    mixins: [Mixin.getByName('notification')],

    props: {
        products: {
            type: Array,
            required: true,
        },
    },

    data() {
        return {
            deleteParent: true,
            isSplitting: false,
        };
    },

    computed: {
        parentIds() {
            return this.products.filter((product) => this.isParent(product)).map((product) => product.id);
        },
    },

    methods: {
        isParent(product) {
            return !product.parentId && product.childCount > 0;
        },

        async onSplit() {
            this.isSplitting = true;

            try {
                const { data } = await Shopware.Application.getContainer('init').httpClient.post(
                    '/_action/optiweb/variant-builder/split',
                    { parentIds: this.parentIds, deleteParent: this.deleteParent },
                    { headers: this.syncService.getBasicHeaders() },
                );

                const variants = data.split.reduce((sum, parent) => sum + parent.variants, 0);
                this.createNotificationSuccess({
                    message: this.$t('optiweb-variant-builder.split.success', { parents: data.split.length, variants }),
                });
                this.$emit('variants-split');
            } catch (error) {
                this.createNotificationError({
                    message: error?.response?.data?.errors?.[0]?.detail || error?.message,
                });
            } finally {
                this.isSplitting = false;
            }
        },
    },
});
