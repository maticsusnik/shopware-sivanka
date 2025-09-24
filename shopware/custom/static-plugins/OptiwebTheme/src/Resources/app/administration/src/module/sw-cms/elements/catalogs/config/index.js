import template from './sw-cms-el-config-catalogs.html.twig';
import './sw-cms-el-config-catalogs.scss';

const {Component, Mixin} = Shopware;

Component.register('sw-cms-el-config-catalogs', {
    template,

    mixins: [
        Mixin.getByName('cms-element')
    ],

    created() {
        this.initElementConfig('catalogs');
    },

    methods: {
        onElementUpdate(element) {
            this.element.config.catalogs.value = element;
            this.$emit('element-update', element);
        },
    }
});
