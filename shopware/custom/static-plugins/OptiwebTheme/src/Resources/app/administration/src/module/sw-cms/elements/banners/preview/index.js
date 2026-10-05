import template from './sw-cms-el-preview-banners.html.twig';
import './sw-cms-el-preview-banners.scss';

// See sivanka-hero/preview: no `cms-element` mixin in a preview component.
Shopware.Component.register('sw-cms-el-preview-banners', {
    template,
});
