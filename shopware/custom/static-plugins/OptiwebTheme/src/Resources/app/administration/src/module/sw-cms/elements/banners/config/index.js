import template from './sw-cms-el-config-banners.html.twig';
import './sw-cms-el-config-banners.scss';

const { Component, Mixin } = Shopware;

const MAX_BANNERS = 3;

// The design lays the three banners out as image-cta / accent-icon / image-card.
// Banners saved before the style field existed carry no `style`, so the storefront and
// this config both fall back to that order by position — keep the two in sync.
const DEFAULT_STYLES = ['image-cta', 'accent-icon', 'image-card'];

// Every key the storefront template reads, with the value it falls back to.
// Legacy banners only carry id/link/media/mediaId/text, so anything missing here would
// otherwise stay `undefined` and hide the field behind its `v-if`.
const BANNER_DEFAULTS = {
    style: '',
    mediaId: null,
    media: null,
    eyebrow: '',
    title: '',
    description: '',
    btnLabel: '',
    text: '',
    link: '',
    bgColor: '#F7DCE7',
    iconType: 'gift',
    textPositionV: 'bottom',
    textPositionH: 'left',
};

Component.register('sw-cms-el-config-banners', {
    template,

    emits: ['element-update'],

    mixins: [
        Mixin.getByName('cms-element')
    ],

    created() {
        this.initElementConfig('banners');
    },

    computed: {
        banners() {
            return this.element.config.banners.value || [];
        },

        styleOptions() {
            return [
                { value: 'image-cta', label: 'Image CTA — photo + eyebrow + title + text + button' },
                { value: 'accent-icon', label: 'Accent + icon — colour background + icon + text link' },
                { value: 'image-card', label: 'Image card — photo + floating white card' },
                { value: 'plain', label: 'Plain — photo with a free HTML overlay (legacy)' },
            ];
        },

        perRowOptions() {
            return [
                { value: 1, label: '1 per row' },
                { value: 2, label: '2 per row' },
                { value: 3, label: '3 per row (design layout)' },
            ];
        },

        iconOptions() {
            return [
                { value: 'gift', label: 'Gift / voucher' },
                { value: 'truck', label: 'Delivery' },
                { value: 'shield', label: 'Quality / guarantee' },
                { value: 'star', label: 'Star / best' },
                { value: 'heart', label: 'Heart / wishlist' },
                { value: 'headphones', label: 'Support' },
                { value: 'return', label: 'Returns' },
            ];
        },

        positionVOptions() {
            return [
                { value: 'top', label: 'Top' },
                { value: 'middle', label: 'Middle' },
                { value: 'bottom', label: 'Bottom' },
            ];
        },

        positionHOptions() {
            return [
                { value: 'left', label: 'Left' },
                { value: 'center', label: 'Center' },
                { value: 'right', label: 'Right' },
            ];
        },
    },

    methods: {
        onElementUpdate() {
            this.$emit('element-update', this.element);
        },

        /**
         * The style a banner is actually rendered with. Never `undefined`, so the field
         * visibility below always resolves — this is what makes the fields of legacy
         * banners (saved without a `style`) visible again.
         */
        effectiveStyle(banner, index) {
            if (banner.style) {
                return banner.style;
            }

            // A banner with no style but with text was authored as the legacy "plain"
            // banner (free HTML overlay); keep it that way so its text stays editable
            // and keeps rendering. Mirrors the storefront template.
            if (banner.text) {
                return 'plain';
            }

            return DEFAULT_STYLES[index] || 'image-cta';
        },

        showsImage(banner, index) {
            return this.effectiveStyle(banner, index) !== 'accent-icon';
        },

        showsAccent(banner, index) {
            return this.effectiveStyle(banner, index) === 'accent-icon';
        },

        showsEyebrow(banner, index) {
            return this.effectiveStyle(banner, index) === 'image-cta';
        },

        showsButton(banner, index) {
            return ['image-cta', 'image-card'].includes(this.effectiveStyle(banner, index));
        },

        showsCopy(banner, index) {
            // The plain style renders only the free HTML overlay, no title/description.
            return this.effectiveStyle(banner, index) !== 'plain';
        },

        showsOverlay(banner, index) {
            return this.effectiveStyle(banner, index) === 'plain';
        },

        /** Fills in every key the storefront reads, without touching existing values. */
        normalizeBanner(banner, index) {
            Object.entries(BANNER_DEFAULTS).forEach(([key, fallback]) => {
                if (banner[key] === undefined) {
                    banner[key] = fallback;
                }
            });

            if (!banner.style) {
                banner.style = this.effectiveStyle(banner, index);
            }

            return banner;
        },

        onBannerChange(banner, index) {
            this.normalizeBanner(banner, index);
            this.onElementUpdate();
        },

        onStyleChange(banner, index, value) {
            banner.style = value;
            this.onBannerChange(banner, index);
        },

        onPerRowChange(value) {
            this.element.config.perRow.value = Number(value);
            this.onElementUpdate();
        },

        onBannerMediaChange(banner, index) {
            // Drop any stale media snapshot; the storefront resolver loads it from mediaId.
            banner.media = null;
            this.onBannerChange(banner, index);
        },

        addBanner() {
            const banners = [...this.banners];

            if (banners.length >= MAX_BANNERS) {
                return;
            }

            const index = banners.length;
            banners.push(this.normalizeBanner({ id: `${Date.now()}-${index}` }, index));

            this.element.config.banners.value = banners;
            this.onElementUpdate();
        },

        removeBanner(index) {
            const banners = [...this.banners];
            banners.splice(index, 1);
            this.element.config.banners.value = banners;
            this.onElementUpdate();
        },
    }
});
