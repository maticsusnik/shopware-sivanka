/* global Shopware */

import template from './ow-url.html.twig';
import "./ow-url.scss";

const {Component} = Shopware;
const LINK_TYPE_EXTERNAL = "external";
const LINK_TYPE_INTERNAL = "internal";
const ENTITY_TYPE_CATEGORY = "category";
const ENTITY_TYPE_PRODUCT = "product";

Component.register('ow-url', {
    template,
    emits: ['input'],
    props: {
        modelValue: {
            type: Object,
            required: false,
            default: () => ({
                entity: null,
                entityId: null,
                link: null,
                text: "",
                type: LINK_TYPE_EXTERNAL
            }),
        },

        label: {
            type: String,
            required: false,
            default: ""
        }
    },
    methods: {
        selectLinkTypeEvent(newValue) {
            this.linkInternal.entity = newValue;
            this.linkInternal.value = null;
        },
        emitInternal() {

            this.$emit("input", {
                type: this.linkType,
                link: null,
                entity: this.linkInternal.entity,
                entityId: this.linkInternal.value,
                text: this.text
            });
        },
        emitExternal() {
            this.$emit("input", {
                type: this.linkType,
                link: this.linkExternal,
                entity: null,
                entityId: null,
                text: this.text
            });
        }
    },
    data: function () {
        return {
            linkType: LINK_TYPE_EXTERNAL,
            text: "",
            linkExternal: "",
            linkInternal: {
                entity: ENTITY_TYPE_CATEGORY,
                value: null
            },
            linkSelector: [
                {
                    label: "External",
                    value: LINK_TYPE_EXTERNAL
                },
                {
                    label: "Internal",
                    value: LINK_TYPE_INTERNAL
                }
            ],
            internalLinkSelector: [
                {
                    label: "Category",
                    value: ENTITY_TYPE_CATEGORY
                },
                {
                    label: "Product",
                    value: ENTITY_TYPE_PRODUCT
                }
            ]
        }
    },
    watch: {
        text() {
            if (this.linkType === LINK_TYPE_EXTERNAL) {
                this.emitExternal();
                return;
            }
            if (this.linkType === LINK_TYPE_INTERNAL) {
                this.emitInternal();
            }
        },
        linkExternal: {
            handler() {
                this.emitExternal();
            }
        },
        linkInternal: {
            deep: true,
            handler() {
                this.emitInternal();
            }
        },
    },
    mounted() {
        if (this.modelValue === null) return;
        if (typeof this.modelValue.type != "undefined") this.linkType = this.modelValue.type;
        if (typeof this.modelValue.link != "undefined") this.linkExternal = this.modelValue.link;
        if (typeof this.modelValue.entity != "undefined") this.linkInternal.entity = this.modelValue.entity;
        if (typeof this.modelValue.entityId != "undefined") this.linkInternal.value = this.modelValue.entityId;
        if (typeof this.modelValue.text != "undefined") this.text = this.modelValue.text;
          },
    created() {
        this.LINK_TYPE_EXTERNAL = LINK_TYPE_EXTERNAL;
        this.LINK_TYPE_INTERNAL = LINK_TYPE_INTERNAL;
        this.ENTITY_TYPE_CATEGORY = ENTITY_TYPE_CATEGORY;
        this.ENTITY_TYPE_PRODUCT = ENTITY_TYPE_PRODUCT;
    }
});
