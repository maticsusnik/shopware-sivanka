/* global Shopware */

import template from './sw-cms-el-category-selection.html.twig';
import './sw-cms-el-category-selection.scss';


const {Component, Mixin} = Shopware;

Component.register('sw-cms-el-category-selection', {
    template,
    mixins: [
        Mixin.getByName('cms-element')
    ],
    created() {
        this.createdComponent();
    },
    methods: {
        createdComponent() {
            this.initElementConfig('category-selection');
        },
    }
});
