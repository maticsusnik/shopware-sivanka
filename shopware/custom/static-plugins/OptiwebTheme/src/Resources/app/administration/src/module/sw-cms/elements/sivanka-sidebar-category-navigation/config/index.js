import template from './sw-cms-el-config-sivanka-category-navigation.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('sw-cms-el-config-sivanka-category-navigation', {
    template,

    emits: ['element-update'],

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('cms-element')
    ],

    data() {
        return {
            parentCategoryIdLocal: '',
            showParentCategoryLocal: 'no',
            showParentCategoryOptions: [
                { value: 'no', label: 'No' },
                { value: 'yes', label: 'Yes' },
            ],
        };
    },

    computed: {
        categoryRepository() {
            return this.repositoryFactory.create('category');
        },

        categoryCriteria() {
            const criteria = new Criteria(1, 100);
            criteria.addFilter(Criteria.range('level', { gte: 1, lte: 2 }));
            return criteria;
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.initElementConfig('sivanka-category-navigation');
            
            // Initialize local values from element config
            const cfg = this.element?.config || {};
            this.parentCategoryIdLocal = cfg.parentCategory?.value || '';
            this.showParentCategoryLocal = cfg.showParentCategory?.value || 'no';
        },

        onElementUpdate() {
            this.$emit('element-update', this.element);
        },
    },

    watch: {
        parentCategoryIdLocal(val) {
            this.element.config.parentCategory.value = val || '';
            this.onElementUpdate();
        },

        showParentCategoryLocal(val) {
            this.element.config.showParentCategory.value = val || 'no';
            this.onElementUpdate();
        },
    }
});

