import template from './sw-cms-el-config-sivanka-product-slider.html.twig';
import './sw-cms-el-config-sivanka-product-slider.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('sw-cms-el-config-sivanka-product-slider', {
    template,
    emits: ['element-update'],
    mixins: [Mixin.getByName('cms-element')],
    inject: ['repositoryFactory'],
    data() {
        return {
            selectionModeLocal: 'manual',
            parentCategoryIdLocal: '',
            productIdsLocal: [],
            imageIdLocal: null,
            selectionModeOptions: [
                { label: 'Manual selection', value: 'manual' },
                { label: 'From category', value: 'from-category' },
            ],
        };
    },
    computed: {
        productRepository() { return this.repositoryFactory.create('product'); },
        categoryRepository() { return this.repositoryFactory.create('category'); },
        categoryCriteria() {
            const criteria = new Criteria(1, 100);
            criteria.addFilter(Criteria.range('level', { gte: 1, lte: 2 }));
            return criteria;
        },
    },
    created() {
        this.initElementConfig('sivanka-product-slider');
        const cfg = this.element?.config || {};
        const mode = cfg.selectionMode?.value;
        this.selectionModeLocal = (mode === 'manual' || mode === 'from-category') ? mode : 'manual';
        this.parentCategoryIdLocal = cfg.parentCategoryId?.value || '';
        this.productIdsLocal = Array.isArray(cfg.productIds?.value) ? cfg.productIds.value : [];
        this.imageIdLocal = cfg.image?.value || null;
    },
    watch: {
        selectionModeLocal(val) {
            this.element.config.selectionMode.value = val;
            if (val === 'manual') this.parentCategoryIdLocal = '';
            if (val === 'from-category') this.productIdsLocal = [];
            this.onElementUpdate();
        },
        parentCategoryIdLocal(val) {
            this.element.config.parentCategoryId.value = val || '';
            this.onElementUpdate();
        },
        productIdsLocal(val) {
            this.element.config.productIds.value = Array.isArray(val) ? val : [];
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
    }
});




