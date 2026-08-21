import template from './sw-cms-el-banners.html.twig';
import './sw-cms-el-banners.scss';

const { Component, Mixin } = Shopware;

// Keep in sync with the storefront template and the config component.
const DEFAULT_STYLES = ['image-cta', 'accent-icon', 'image-card'];

Component.register('sw-cms-el-banners', {
    template,

    mixins: [
        Mixin.getByName('cms-element')
    ],

    computed: {
        banners() {
            return this.element.config.banners.value || [];
        },
    },

    created() {
        this.initElementConfig('banners');
    },

    methods: {
        effectiveStyle(banner, index) {
            if (banner.style) {
                return banner.style;
            }

            if (banner.text) {
                return 'plain';
            }

            return DEFAULT_STYLES[index] || 'image-cta';
        },

        bannerLabel(banner, index) {
            const style = this.effectiveStyle(banner, index);

            return {
                'image-cta': 'Image CTA',
                'accent-icon': 'Accent + icon',
                'image-card': 'Image card',
                plain: 'Plain overlay',
            }[style] || style;
        },
    }
});
