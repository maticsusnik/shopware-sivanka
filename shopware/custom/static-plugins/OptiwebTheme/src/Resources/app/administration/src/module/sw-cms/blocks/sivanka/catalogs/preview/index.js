import template from './sw-cms-preview-catalogs.html.twig';
import './sw-cms-preview-catalogs.scss';

Shopware.Component.register('sw-cms-preview-catalogs', {
    template,
    computed: {
        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },
    },
});
