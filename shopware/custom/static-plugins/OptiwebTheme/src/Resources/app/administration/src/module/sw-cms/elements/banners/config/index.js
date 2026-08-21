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
            if (banners.length >= 3) { return; }
            banners.push({
                id: Date.now(),
                media: null,
                style: 'plain',
                title: '',
                eyebrow: '',
                text: '',
                description: '',
                btnLabel: '',
                textPositionV: 'bottom',
                textPositionH: 'left',
                link: '',
                bgColor: '#F7DCE7',
                iconType: 'gift',
            });
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
        onBannerTextPositionChange() {
            this.onElementUpdate();
        },
        onBannerLinkChange() {
            this.onElementUpdate();
        },
        onBannerMediaChange(banner, mediaId) {
            banner.media = null;
            banner.mediaId = mediaId;
            this.onElementUpdate();
        },
        onStyleChange() {
            this.onElementUpdate();
        },
        onTitleChange() {
            this.onElementUpdate();
        },
        onEyebrowChange() {
            this.onElementUpdate();
        },
        onDescriptionChange() {
            this.onElementUpdate();
        },
        onBtnLabelChange() {
            this.onElementUpdate();
        },
        onBgColorChange() {
            this.onElementUpdate();
        },
        onIconTypeChange() {
            this.onElementUpdate();
        },
    }
});
