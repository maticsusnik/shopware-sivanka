import template from "./sw-cms-block-layout-config.html.twig";

const {Component} = Shopware;

Component.override('sw-cms-block-layout-config', {
    template,

    created() {
        // The layout lives in `block.customFields`, which is null on blocks created
        // before the option existed. Without this the `v-model` below would have nothing
        // to write into and the field would stay hidden.
        if (this.supportsLayout && !this.block.customFields) {
            this.block.customFields = {};
        }
    },

    computed: {
        /**
         * Whether the block *type* declares a layout option, taken from its registration
         * rather than from the saved value — otherwise the field would only ever appear
         * for blocks that already have a layout stored.
         */
        supportsLayout() {
            const registry = Shopware.Service('cmsService').getCmsBlockRegistry();

            return registry[this.block?.type]?.defaultConfig?.customFields?.layout !== undefined;
        },

        shouldShowLayoutSelectionField() {
            return this.supportsLayout;
        },

        layoutValue() {
            return this.block?.customFields?.layout || this.layoutOptions[0].value;
        },

        layoutOptions() {
            return [
                {
                    id: 1,
                    value: 'text-left-next-to-image',
                    label: "Text left (Next to image)",
                },
                {
                    id: 2,
                    value: 'text-right-over-image',
                    label: "Text right (Overlapped with image)",
                }
            ];
        },
    },

    methods: {
        onChangeLayoutOption(value) {
            if (!this.block.customFields) {
                this.block.customFields = {};
            }

            this.block.customFields.layout = value;
        },
    },
});
