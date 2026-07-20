import template from './sw-cms-el-config-sivanka-hero.html.twig';
import './sw-cms-el-config-sivanka-hero.scss';

const { Component, Mixin } = Shopware;
Component.register('sw-cms-el-config-sivanka-hero', {
    template,
    mixins: [Mixin.getByName('cms-element')],
    data() {
        return {
            iconOptions: [
                { value: 'users', label: 'Users / Community' },
                { value: 'truck', label: 'Truck / Delivery' },
                { value: 'shield', label: 'Shield / Quality' },
                { value: 'headphones', label: 'Headphones / Support' },
                { value: 'star', label: 'Star' },
                { value: 'heart', label: 'Heart' },
                { value: 'check', label: 'Check / Verified' },
                { value: 'return', label: 'Return / Exchange' },
            ]
        };
    },
    created() { this.initElementConfig('sivanka-hero'); },
    methods: {
        onUpdate() { this.$emit('element-update', this.element); }
    }
});
