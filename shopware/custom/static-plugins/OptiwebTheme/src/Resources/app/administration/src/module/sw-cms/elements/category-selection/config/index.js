import template from './sw-cms-el-config-category-selection.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('sw-cms-el-config-category-selection', {
    template,

    emits: ['element-update'],

    inject: ['repositoryFactory'],
    mixins: [Mixin.getByName('cms-element')],

    data() {
        return {
            selectionModeLocal: 'single', // 'single' | 'multiple'
            parentCategoryIdLocal: '',
            categoryIdsLocal: [],
            imageIdLocal: null,
            selectionModeOptions: [
                { label: 'Single parent category', value: 'single' },
                { label: 'Multiple categories', value: 'multiple' },
            ],
        };
    },

    computed: {
        categoryRepository() {
            return this.repositoryFactory.create('category');
        },
        // mediaRepository removed since not used

        categoryCriteria() {
            const criteria = new Criteria(1, 100);
            criteria.addFilter(Criteria.range('level', { gte: 1, lte: 2 }));
            return criteria;
        },
    },

    created() {
        // Merges the element's defaultConfig into `element.config`, so slots saved before a
        // field existed still have it. Without this the watchers below write to `undefined`.
        this.initElementConfig('category-selection');

        const cfg = this.element?.config || {};
        const mode = cfg.selectionMode?.value;
        this.selectionModeLocal = (mode === 'multiple' || mode === 'single') ? mode : 'single';

        this.parentCategoryIdLocal = cfg.parentCategoryId?.value || '';
        this.categoryIdsLocal = Array.isArray(cfg.categories?.value) ? cfg.categories.value : [];
        this.imageIdLocal = cfg.image?.value || null;
    },

    watch: {
        selectionModeLocal(val) {
            this.element.config.selectionMode.value = val;

            // remove these two lines if you want to keep both values when toggling
            if (val === 'single') this.categoryIdsLocal = [];
            if (val === 'multiple') this.parentCategoryIdLocal = '';

            this.onElementUpdate();
        },

        parentCategoryIdLocal(val) {
            this.element.config.parentCategoryId.value = val || '';
            this.onElementUpdate();
        },

        categoryIdsLocal(val) {
            this.element.config.categories.value = Array.isArray(val) ? val : [];
            this.onElementUpdate();
        },

        imageIdLocal(val) {
            this.element.config.image.value = val || null;
            this.onElementUpdate();
        },
    },

    methods: {
        onElementUpdate() {
            this.$emit('element-update', this.element);
        },
    },
});
