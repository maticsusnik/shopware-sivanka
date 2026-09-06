import template from './sw-cms-el-config-sivanka-service-intro.html.twig';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-config-sivanka-service-intro', {
    template,

    emits: ['element-update'],

    mixins: [Mixin.getByName('cms-element')],

    computed: {
        headlineLevelOptions() {
            return [
                { value: 'h1', label: this.$tc('sw-cms.elements.sivankaServiceIntro.config.label.headlineLevelH1') },
                { value: 'h2', label: this.$tc('sw-cms.elements.sivankaServiceIntro.config.label.headlineLevelH2') },
                { value: 'h3', label: this.$tc('sw-cms.elements.sivankaServiceIntro.config.label.headlineLevelH3') },
            ];
        },

        variantOptions() {
            return [
                { value: 'hero', label: this.$tc('sw-cms.elements.sivankaServiceIntro.config.label.variantHero') },
                { value: 'cta', label: this.$tc('sw-cms.elements.sivankaServiceIntro.config.label.variantCta') },
                { value: 'plain', label: this.$tc('sw-cms.elements.sivankaServiceIntro.config.label.variantPlain') },
                { value: 'home', label: this.$tc('sw-cms.elements.sivankaServiceIntro.config.label.variantHome') },
            ];
        },
    },

    created() {
        this.initElementConfig('sivanka-service-intro');
    },

    methods: {
        onChange() {
            this.$emit('element-update', this.element);
        },
    },
});
