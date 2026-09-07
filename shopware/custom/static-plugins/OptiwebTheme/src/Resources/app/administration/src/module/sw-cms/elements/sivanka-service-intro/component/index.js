import template from './sw-cms-el-sivanka-service-intro.html.twig';
import './sw-cms-el-sivanka-service-intro.scss';

const { Component, Mixin } = Shopware;

const HEADLINE_TAGS = ['h1', 'h2', 'h3'];

// Variants the design centres and gives the rule-heart-rule flourish to.
const FLOURISH_VARIANTS = ['home', 'cta'];

Component.register('sw-cms-el-sivanka-service-intro', {
    template,

    mixins: [Mixin.getByName('cms-element')],

    computed: {
        headlineTag() {
            const level = this.element?.config?.headlineLevel?.value;

            return HEADLINE_TAGS.includes(level) ? level : 'h2';
        },

        hasFlourish() {
            return FLOURISH_VARIANTS.includes(this.element?.config?.variant?.value)
                && Boolean(this.element?.config?.headline?.value);
        },
    },

    created() {
        this.initElementConfig('sivanka-service-intro');
    },
});
