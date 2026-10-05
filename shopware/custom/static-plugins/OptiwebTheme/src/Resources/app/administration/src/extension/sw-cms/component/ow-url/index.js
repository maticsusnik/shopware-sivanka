/* global Shopware */

import template from './ow-url.html.twig';
import "./ow-url.scss";

const {Component} = Shopware;
const LINK_TYPE_EXTERNAL = "external";
const LINK_TYPE_INTERNAL = "internal";
const ENTITY_TYPE_CATEGORY = "category";
const ENTITY_TYPE_PRODUCT = "product";

const ENTITY_TYPES = [ENTITY_TYPE_CATEGORY, ENTITY_TYPE_PRODUCT];

Component.register('ow-url', {
    template,

    // `update:modelValue` is what `v-model` listens for; `input` is kept because the
    // existing call sites bind `@input="..."` and rely on being handed the new value
    // *before* v-model writes it back (their guards compare against the old value).
    emits: ['update:modelValue', 'input'],

    props: {
        modelValue: {
            type: Object,
            required: false,
            default: null,
        },

        label: {
            type: String,
            required: false,
            default: ""
        },

        /**
         * The hero keeps its own "button label" field, so it hides the one built into
         * this component instead of showing the editor two inputs for the same text.
         */
        hideText: {
            type: Boolean,
            required: false,
            default: false
        }
    },

    data() {
        return {
            linkType: LINK_TYPE_EXTERNAL,
            text: "",
            linkExternal: "",
            linkInternal: {
                entity: ENTITY_TYPE_CATEGORY,
                value: null
            },
            // Suppresses the watchers while `hydrate()` writes the incoming value into
            // the local fields — without it every mount would emit and mark the CMS page
            // dirty, and re-hydrating would echo the parent's own value back at it.
            isHydrating: false,
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

    computed: {
        currentValue() {
            if (this.linkType === LINK_TYPE_INTERNAL) {
                return {
                    type: LINK_TYPE_INTERNAL,
                    link: null,
                    entity: this.linkInternal.entity,
                    entityId: this.linkInternal.value,
                    text: this.text
                };
            }

            return {
                type: LINK_TYPE_EXTERNAL,
                link: this.linkExternal || null,
                entity: null,
                entityId: null,
                text: this.text
            };
        }
    },

    watch: {
        modelValue: {
            deep: true,
            handler(newValue) {
                // Only re-hydrate when the parent actually holds something different —
                // otherwise our own emit would bounce straight back in.
                if (JSON.stringify(newValue ?? null) === JSON.stringify(this.currentValue)) {
                    return;
                }

                this.hydrate(newValue);
            }
        },

        linkType() {
            this.emitValue();
        },

        text() {
            this.emitValue();
        },

        linkExternal() {
            this.emitValue();
        },

        linkInternal: {
            deep: true,
            handler() {
                this.emitValue();
            }
        },
    },

    created() {
        this.LINK_TYPE_EXTERNAL = LINK_TYPE_EXTERNAL;
        this.LINK_TYPE_INTERNAL = LINK_TYPE_INTERNAL;
        this.ENTITY_TYPE_CATEGORY = ENTITY_TYPE_CATEGORY;
        this.ENTITY_TYPE_PRODUCT = ENTITY_TYPE_PRODUCT;

        this.hydrate(this.modelValue);
    },

    methods: {
        /**
         * Fills the local fields from a stored value.
         *
         * `entity` is deliberately *not* copied when it is empty: an external link is
         * saved with `entity: null`, and taking that over used to leave the entity select
         * on no value at all, so neither the category nor the product picker matched its
         * `v-if` and switching to "Internal" showed no way to pick a target.
         */
        hydrate(value) {
            this.isHydrating = true;

            const source = value && typeof value === 'object' ? value : {};

            this.linkType = source.type === LINK_TYPE_INTERNAL ? LINK_TYPE_INTERNAL : LINK_TYPE_EXTERNAL;
            this.linkExternal = source.link ?? "";
            this.text = source.text ?? "";
            this.linkInternal.entity = ENTITY_TYPES.includes(source.entity) ? source.entity : ENTITY_TYPE_CATEGORY;
            this.linkInternal.value = source.entityId ?? null;

            this.$nextTick(() => {
                this.isHydrating = false;
            });
        },

        selectLinkTypeEvent(newValue) {
            // Switching between category and product invalidates the picked id.
            if (this.linkInternal.entity !== newValue) {
                this.linkInternal.entity = newValue;
            }

            this.linkInternal.value = null;
        },

        emitValue() {
            if (this.isHydrating) {
                return;
            }

            const value = this.currentValue;

            // `input` first: the call sites' handlers compare the incoming value against
            // the currently stored one to decide whether to emit `element-update`.
            this.$emit("input", value);
            this.$emit("update:modelValue", value);
        }
    }
});
