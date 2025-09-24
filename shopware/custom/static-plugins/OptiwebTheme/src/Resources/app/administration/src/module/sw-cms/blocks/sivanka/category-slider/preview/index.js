/* global Shopware */

import template from './sw-cms-preview-category-slider.html.twig';
import './sw-cms-preview-category-slider.scss';

const { Component } = Shopware;

Component.register('sw-cms-preview-category-slider', {
    template,
    computed: {
        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },
    },
});
