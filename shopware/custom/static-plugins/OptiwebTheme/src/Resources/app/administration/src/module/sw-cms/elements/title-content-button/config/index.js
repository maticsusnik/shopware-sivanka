import template from './sw-cms-el-config-title-content-button.html.twig';

Shopware.Component.register('sw-cms-el-config-title-content-button', {
    template,

    data() {
        return {
            titleTypeOptions: [
                {
                    id: 1,
                    label: "h2",
                    value: 'h2'
                },
                {
                    id: 2,
                    label: "h3",
                    value: 'h3'
                }
            ]
        }
    },

    emits: ['element-update'],

    mixins: [
        Shopware.Mixin.getByName('cms-element')
    ],

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.initElementConfig('title-content-button');
        },
        onBlur(content) {
            this.emitChanges(content);
        },

        onInput(content) {
            this.emitChanges(content);
        },

        emitChanges(content) {
            if (content !== this.element.config.content.value) {
                this.element.config.content.value = content;
                this.$emit('element-update', this.element);
            }
        },

        handleButtonInput(newValue) {
            if (newValue !== this.element.config.button.value) {
                this.element.config.button.value = newValue;
                this.$emit('element-update', this.element);
            }
        },

    }
});