import template from './sw-cms-el-config-sivanka-service-card.html.twig';
import './sw-cms-el-config-sivanka-service-card.scss';
import { sivankaIconOptions } from '../../sivanka-service-icons';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-config-sivanka-service-card', {
    template,

    inject: ['repositoryFactory'],

    emits: ['element-update'],

    mixins: [Mixin.getByName('cms-element')],

    data() {
        return {
            mediaModalIsOpen: false,
        };
    },

    computed: {
        mediaRepository() {
            return this.repositoryFactory.create('media');
        },

        uploadTag() {
            return `cms-element-sivanka-service-card-config-${this.element.id}`;
        },

        previewSource() {
            if (this.element?.data?.media?.id) {
                return this.element.data.media;
            }

            return this.element.config.media.value;
        },

        iconOptions() {
            return sivankaIconOptions(this.$tc);
        },

        variantOptions() {
            return [
                { value: 'wide', label: this.$tc('sw-cms.elements.sivankaServiceCard.config.label.variantWide') },
                { value: 'compact', label: this.$tc('sw-cms.elements.sivankaServiceCard.config.label.variantCompact') },
                { value: 'tile', label: this.$tc('sw-cms.elements.sivankaServiceCard.config.label.variantTile') },
            ];
        },
    },

    created() {
        this.initElementConfig('sivanka-service-card');
    },

    methods: {
        onChange() {
            this.$emit('element-update', this.element);
        },

        async onImageUpload({ targetId }) {
            const media = await this.mediaRepository.get(targetId);

            this.setMedia(media);
        },

        onImageRemove() {
            this.setMedia(null);
        },

        onOpenMediaModal() {
            this.mediaModalIsOpen = true;
        },

        onCloseMediaModal() {
            this.mediaModalIsOpen = false;
        },

        onSelectionChanges(mediaItems) {
            this.setMedia(mediaItems[0] ?? null);
        },

        setMedia(media) {
            this.element.config.media.value = media ? media.id : null;
            this.element.config.media.source = 'static';

            if (!this.element.data) {
                this.element.data = {};
            }

            this.element.data.mediaId = media ? media.id : null;
            this.element.data.media = media;

            this.$emit('element-update', this.element);
        },
    },
});
