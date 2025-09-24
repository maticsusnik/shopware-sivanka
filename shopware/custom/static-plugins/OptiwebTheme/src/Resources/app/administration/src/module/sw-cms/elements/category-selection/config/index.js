import template from './sw-cms-el-config-category-selection.html.twig';

const {Criteria, EntityCollection} = Shopware.Data;

Shopware.Component.register('sw-cms-el-config-category-selection', {
    template,
    inject: [
        'repositoryFactory',
    ],
    mixins: [
        'cms-element'
    ],
    data() {
        return {
            mainCategoriesCollection: null,
            categoryCollection: undefined,
            selectedCategory: '',
        };
    },

    created() {
        this.createdComponent();
    },
    computed: {
        mainCategories() {
            return this.categoryCollection ? this.categoryCollection : [];
        },
        categoryRepository() {
            return this.repositoryFactory.create('category');
        },
        categoryCriteria() {
            const criteria = new Criteria(1, 100);
            criteria.addFilter(Criteria.range('level', {
                gte: 1, // Level 1 (Root)
                lte: 2, // Up to Level 2
            }));

            if (this.selectedCategory) {
                criteria.setIds([this.selectedCategory]);
            }


            return criteria;
        }

    },
    methods: {
        async createdComponent() {
            this.selectedCategory = this.element.config.category.value;

            this.categoryCollection = this.getEmptyCategoryCollection();

            if (this.selectedCategory) {
                try {
                    const criteria = new Criteria();
                    criteria.setIds([this.selectedCategory]);

                    const result = await this.categoryRepository.search(criteria, Shopware.Context.api);

                    if (result.length > 0) {
                        this.categoryCollection.push(...result);
                    }
                } catch (error) {
                    console.error("Error fetching selected category data:", error);
                }
            }

        },

        getEmptyCategoryCollection() {
            return new EntityCollection(
                this.categoryRepository.route,
                this.categoryRepository.entityName,
                Shopware.Context.api,
            );
        },

        onCategoryAdd(category) {
            this.selectedCategory = category.id;
            this.element.config.category.value = category.id;
            this.element.translated.config.category.value = category.id;
        },

        onCategoryRemove() {
            this.selectedCategory = '';
            this.element.config.category.value = '';
            this.element.translated.config.category.value = '';
        },
    }

});