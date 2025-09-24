import template from './sw-category-detail-base.html.twig';
import {customFieldSetAndGet} from "../../helper";

const { Component } = Shopware;

Component.override('sw-category-detail-base', {
    template,

    mounted() {
        if (!this.category.customFields) {
            this.category.customFields = {};
        }
    },
    methods: {
        onSetMediaItem({ targetId }) {
            this.mediaRepository.get(targetId).then((updatedMedia) => {
                this.category.customFields.categoryIconId = targetId;
                this.category.customFields.categoryIcon = updatedMedia;
            });
        },
        onRemoveMediaItem() {
            this.category.customFields.categoryIconId = null;
            this.category.customFields.categoryIcon = null;
        },
        onCategoryGridTitleInput(newValue) {
            this.category.customFields.categoryGridTitle = newValue;
        }
    },
    computed: {
        categoryIcon() {
            return this.category?.customFields?.categoryIcon || null;
        },
        mediaRepository() {
            return this.repositoryFactory.create('media');
        },
        categoryGridTitle: customFieldSetAndGet("category", "categoryGridTitle", ''),
    },
});
