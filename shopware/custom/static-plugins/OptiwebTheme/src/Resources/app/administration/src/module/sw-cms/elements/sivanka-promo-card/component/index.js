import template from './sw-cms-el-sivanka-promo-card.html.twig';
import './sw-cms-el-sivanka-promo-card.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-sivanka-promo-card', {
    template,

    inject: ['repositoryFactory'],

    mixins: [Mixin.getByName('cms-element')],

    data() {
        return {
            // `element.data` is only populated by the storefront resolver, so the
            // canvas loads the picture itself.
            media: null,
        };
    },

    computed: {
        mediaRepository() {
            return this.repositoryFactory.create('media');
        },

        mediaId() {
            return this.element?.config?.media?.value ?? null;
        },
    },

    watch: {
        mediaId: {
            immediate: true,
            async handler(id) {
                if (!id) {
                    this.media = null;

                    return;
                }

                try {
                    this.media = await this.mediaRepository.get(id, Shopware.Context.api);
                } catch {
                    this.media = null;
                }
            },
        },
    },

    created() {
        this.initElementConfig('sivanka-promo-card');
    },
});
