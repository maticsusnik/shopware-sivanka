/* global Shopware */

import template from './sw-cms-el-config-faq.html.twig';
import './sw-cms-el-config-faq.scss';

const {Component, Mixin} = Shopware;


const defaultFaq = {
    question: "",
    answer: ""
}

Component.register('sw-cms-el-config-faq', {
    template,
    inject: ['repositoryFactory'],
    mixins: [
        Mixin.getByName('cms-element')
    ],
    created() {
        this.createdComponent();
    },
    methods: {
        createNewFaq() {
            this.faqs.push({...defaultFaq});
        },
        removeFaq(index) {
            this.element.config.faq.value.splice(index, 1);
        },
        createdComponent() {
            this.initElementConfig('faq');
        },
    },
    computed: {
        faqs() {
            return this.element.config.faq.value;
        }
    }
});
