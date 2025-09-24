import template from './sw-cms-preview-read-more.html.twig';
import './sw-cms-preview-read-more.scss';

const {Component} = Shopware;

Component.register('sw-cms-preview-read-more', {
    template,
    computed: {
        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },
    },
});
