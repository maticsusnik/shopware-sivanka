const {Component} = Shopware;
import template from "./sw-cms-block-layout-config.html.twig";

Component.override('sw-cms-block-layout-config', {
    template,
    computed: {
        shouldShowLayoutSelectionField() {
            return this.block?.customFields?.layout != null;
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
    }
});
