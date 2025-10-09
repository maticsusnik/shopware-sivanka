import template from './sw-cms-el-config-category-selection.html.twig';

const { Criteria } = Shopware.Data;

Shopware.Component.register('sw-cms-el-config-category-selection', {
    template,

    inject: ['repositoryFactory'],
    mixins: ['cms-element'],

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
        },

        parentCategoryIdLocal(val) {
            this.element.config.parentCategoryId.value = val || '';
        },

        categoryIdsLocal(val) {
            this.element.config.categories.value = Array.isArray(val) ? val : [];
        },

        imageIdLocal(val) {
            this.element.config.image.value = val || null;
        },
    },
});
