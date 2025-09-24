/* global Shopware */

import template from './sw-cms-el-title-content-button.html.twig';
import './sw-cms-el-title-content-button.scss';


const {Component, Mixin} = Shopware;

Component.register('sw-cms-el-title-content-button', {
    template,
    mixins: [
        Mixin.getByName('cms-element')
    ],
    created() {
        this.createdComponent();
    },
    methods: {
        createdComponent() {
            this.initElementConfig('title-content-button');
        },
    }
});
