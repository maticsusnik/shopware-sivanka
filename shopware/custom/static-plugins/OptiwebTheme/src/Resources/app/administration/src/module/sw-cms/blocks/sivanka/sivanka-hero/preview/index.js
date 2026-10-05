import template from './sw-cms-preview-sivanka-hero.html.twig';
import './sw-cms-preview-sivanka-hero.scss';

Shopware.Component.register('sw-cms-preview-sivanka-hero', {
    template,

    computed: {
        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },
    },
});
