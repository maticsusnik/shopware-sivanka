import template from './sw-cms-preview-documents.html.twig';
import './sw-cms-preview-documents.scss';

const {Component} = Shopware;

Component.register('sw-cms-preview-documents', {
    template,
    computed: {
        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },
    },
});
