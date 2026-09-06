import template from './sw-cms-preview-sivanka-product-slider.html.twig';
import './sw-cms-preview-sivanka-product-slider.scss';

Shopware.Component.register('sw-cms-preview-sivanka-product-slider', {
    template,

    computed: {
        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },
    },
});
