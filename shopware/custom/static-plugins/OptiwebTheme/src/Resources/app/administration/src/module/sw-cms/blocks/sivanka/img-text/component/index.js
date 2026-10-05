import template from './sw-cms-block-img-text.html.twig';
import './sw-cms-block-img-text.scss';

const {Component} = Shopware;

const DEFAULT_LAYOUT = 'text-right-over-image';

Component.register('sw-cms-block-img-text', {
    template,

    computed: {
        // `customFields` is null on blocks that predate the layout option (and on
        // duplicated blocks). Reading `.layout` off null throws while rendering, which
        // blanks the whole block in the editor — slots and their fields included.
        layoutClass() {
            return this.block?.customFields?.layout || DEFAULT_LAYOUT;
        },
    },
});
