import template from './sw-category-detail-base.html.twig';
import {customFieldSetAndGet} from "../../helper";

const { Component } = Shopware;

Component.override('sw-category-detail-base', {
    template,

    data() {
        return {
            iconModalIsOpen: false,
            tileModalIsOpen: false,
        };
    },

    mounted() {
        if (!this.category.customFields) {
            this.category.customFields = {};
        }
    },
    methods: {
        // --- category icon (custom field, 24px glyph in the slider badge) ---
        onSetMediaItem({ targetId }) {
            this.mediaRepository.get(targetId).then((updatedMedia) => {
                this.setCategoryIcon(updatedMedia);
            });
        },
        onRemoveMediaItem() {
            this.setCategoryIcon(null);
        },
        onIconSelectionChange(mediaItems) {
            this.setCategoryIcon(mediaItems[0] ?? null);
        },
        setCategoryIcon(media) {
            this.category.customFields.categoryIconId = media ? media.id : null;
            this.category.customFields.categoryIcon = media;
        },

        // --- category tile image (category.media, shown in the slider/grid tile) ---
        onSetTileMediaItem({ targetId }) {
            this.mediaRepository.get(targetId).then((updatedMedia) => {
                this.setTileMedia(updatedMedia);
            });
        },
        onRemoveTileMediaItem() {
            this.setTileMedia(null);
        },
        onTileSelectionChange(mediaItems) {
            this.setTileMedia(mediaItems[0] ?? null);
        },
        setTileMedia(media) {
            this.category.mediaId = media ? media.id : null;
            this.category.media = media;
        },

        onCategoryGridTitleInput(newValue) {
            this.category.customFields.categoryGridTitle = newValue;
        }
    },
    computed: {
        categoryIcon() {
            return this.category?.customFields?.categoryIcon || null;
        },
        categoryTileMedia() {
            return this.category?.media || this.category?.mediaId || null;
        },
        iconUploadTag() {
            return `${this.category.id}categoryIcon`;
        },
        tileUploadTag() {
            return `${this.category.id}categoryTile`;
        },
        mediaRepository() {
            return this.repositoryFactory.create('media');
        },
        categoryGridTitle: customFieldSetAndGet("category", "categoryGridTitle", ''),
    },
});
