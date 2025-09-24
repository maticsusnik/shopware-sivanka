/* global Shopware */

import template from './sw-cms-preview-main-slider.html.twig';
import './sw-cms-preview-main-slider.scss';

const { Component } = Shopware;

Component.register('sw-cms-preview-main-slider', {
    template,
    computed: {
        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },
    },
});
