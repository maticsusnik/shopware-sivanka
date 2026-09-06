import template from './sw-cms-el-config-sivanka-hero.html.twig';
import './sw-cms-el-config-sivanka-hero.scss';
import { toLink } from '../../../link-value';
import { sivankaIconOptions } from '../../sivanka-service-icons';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-config-sivanka-hero', {
    template,

    emits: ['element-update'],

    mixins: [Mixin.getByName('cms-element')],

    computed: {
        iconOptions() {
            return sivankaIconOptions(this.$tc);
        },

        trustSlots() {
            return [1, 2, 3, 4];
        },
    },

    created() {
        this.initElementConfig('sivanka-hero');

        // Heroes saved before the buttons supported internal links hold a plain URL
        // string here; `ow-url` needs the object shape, so lift them in place.
        this.element.config.primaryBtnUrl.value = toLink(this.element.config.primaryBtnUrl.value);
        this.element.config.secondaryBtnUrl.value = toLink(this.element.config.secondaryBtnUrl.value);
    },

    methods: {
        onChange() {
            this.$emit('element-update', this.element);
        },

        onPrimaryUrlChange(value) {
            this.element.config.primaryBtnUrl.value = value;
            this.onChange();
        },

        onSecondaryUrlChange(value) {
            this.element.config.secondaryBtnUrl.value = value;
            this.onChange();
        },
    },
});
