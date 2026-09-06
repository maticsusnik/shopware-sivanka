import template from './sw-cms-el-preview-sivanka-hero.html.twig';
import './sw-cms-el-preview-sivanka-hero.scss';

// Preview components are rendered by the element picker with only `:element-data`, so they
// must not use the `cms-element` mixin — its `element` prop is required and never passed.
Shopware.Component.register('sw-cms-el-preview-sivanka-hero', {
    template,
});
