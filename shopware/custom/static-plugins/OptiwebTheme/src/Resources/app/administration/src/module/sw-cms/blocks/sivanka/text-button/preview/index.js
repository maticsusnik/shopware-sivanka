import template from './sw-cms-preview-text-button.html.twig';
import './sw-cms-preview-text-button.scss';

const {Component} = Shopware;

Component.register('sw-cms-preview-text-button', {
    template,
    computed: {
        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },
    },
});
