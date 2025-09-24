/* global Shopware */

import template from './sw-cms-el-read-more-text.html.twig';
import './sw-cms-el-read-more-text.scss';


const {Component, Mixin} = Shopware;

Component.register('sw-cms-el-read-more-text', {
    template,
    mixins: [
        Mixin.getByName('cms-element')
    ],
    created() {
        this.createdComponent();
    },
    methods: {
        createdComponent() {
            this.initElementConfig('read-more-text');
        },
    }
});
