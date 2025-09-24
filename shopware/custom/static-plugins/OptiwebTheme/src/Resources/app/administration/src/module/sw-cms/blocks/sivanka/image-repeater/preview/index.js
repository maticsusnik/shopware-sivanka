/* global Shopware */

import template from './sw-cms-preview-image-repeater.html.twig';
import './sw-cms-preview-image-repeater.scss';

const { Component } = Shopware;

Component.register('sw-cms-preview-image-repeater', {
    template,
    computed: {
        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },
    },
});
