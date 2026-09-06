import template from './sw-cms-el-config-catalogs.html.twig';
import './sw-cms-el-config-catalogs.scss';

const {Component, Mixin} = Shopware;

Component.register('sw-cms-el-config-catalogs', {
    template,

    emits: ['element-update'],

    mixins: [
        Mixin.getByName('cms-element')
    ],

    created() {
        this.initElementConfig('catalogs');
    },

    methods: {
        // The repeater hands over the catalogs array, not a CMS element — `element-update`
        // must still carry this element so the page form updates the right slot.
        onElementUpdate(catalogs) {
            this.element.config.catalogs.value = catalogs;
            this.$emit('element-update', this.element);
        },
    }
});
