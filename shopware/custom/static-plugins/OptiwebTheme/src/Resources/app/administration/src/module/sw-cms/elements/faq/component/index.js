/* global Shopware */

import template from './sw-cms-el-faq.html.twig';
import './sw-cms-el-faq.scss';


const {Component, Mixin} = Shopware;

Component.register('sw-cms-el-faq', {
    template,
    mixins: [
        Mixin.getByName('cms-element')
    ],
    inject: ['repositoryFactory'],
    created() {
        this.createdComponent();
    },
    methods: {
        createdComponent() {
            this.initElementConfig('faq');
        },
    }
});
