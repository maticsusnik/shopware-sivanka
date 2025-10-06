import template from './sw-cms-el-preview-sivanka-product-slider.html.twig';
import './sw-cms-el-preview-sivanka-product-slider.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-preview-sivanka-product-slider', { template, mixins: [Mixin.getByName('cms-element')] });




