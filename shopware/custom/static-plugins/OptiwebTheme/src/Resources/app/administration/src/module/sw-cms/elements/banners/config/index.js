import template from './sw-cms-el-config-banners.html.twig';
import './sw-cms-el-config-banners.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-config-banners', {
    template,

    mixins: [
        Mixin.getByName('cms-element')
    ],

    created() {
        this.initElementConfig('banners');
    },

    methods: {
        onElementUpdate() {
            this.$emit('element-update', this.element);
        },
        onPerRowChange(value) {
            this.element.config.perRow.value = Number(value);
            this.onElementUpdate();
        },
        onSectionTitleChange() {
            this.onElementUpdate();
        },
        addBanner() {
            const banners = this.element.config.banners.value || [];
            if (banners.length >= 6) { return; }
            banners.push({ id: Date.now(), media: null, text: '', link: '' });
            this.element.config.banners.value = [...banners];
            this.onElementUpdate();
        },
        removeBanner(index) {
            const banners = this.element.config.banners.value || [];
            banners.splice(index, 1);
            this.element.config.banners.value = [...banners];
            this.onElementUpdate();
        },
        onBannerTextChange() {
            this.onElementUpdate();
        },
        onBannerLinkChange() {
            this.onElementUpdate();
        },
        onBannerMediaChange(banner, mediaId) {
            // store only mediaId for consistency with Shopware patterns
            banner.media = null;
            banner.mediaId = mediaId;
            this.onElementUpdate();
        }
    }
});



