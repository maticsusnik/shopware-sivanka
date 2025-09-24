import template from './sw-cms-preview-img-text.html.twig';
import './sw-cms-preview-img-text.scss';

const {Component} = Shopware;

Component.register('sw-cms-preview-img-text', {
    template,
    computed: {
        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },
    },
});
