import template from './sw-cms-el-sivanka-category-navigation.html.twig';
import './sw-cms-el-sivanka-category-navigation.scss';

Shopware.Component.register('sw-cms-el-sivanka-category-navigation', {
    template,

    mixins: [
        Shopware.Mixin.getByName('cms-element'),
        Shopware.Mixin.getByName('placeholder'),
    ],

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.initElementConfig('sivanka-category-navigation');
        },
    },
});

