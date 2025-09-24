import template from './sw-cms-el-catalogs.html.twig';
import './sw-cms-el-catalogs.scss';

const {Component, Mixin} = Shopware;

Component.register('sw-cms-el-catalogs', {
    template,

    mixins: [
        Mixin.getByName('cms-element')
    ],

    created() {
        this.initElementConfig('catalogs');
    }
});
