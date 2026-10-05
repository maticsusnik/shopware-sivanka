import template from './sw-cms-el-preview-sivanka-product-slider.html.twig';
import './sw-cms-el-preview-sivanka-product-slider.scss';

// See sivanka-hero/preview: no `cms-element` mixin in a preview component.
Shopware.Component.register('sw-cms-el-preview-sivanka-product-slider', {
    template,
});
