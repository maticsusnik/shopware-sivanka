import template from './optiweb-variant-builder-modal.html.twig';
import './optiweb-variant-builder-modal.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Turns the products selected in the product list into variants of a new or an
 * existing parent. Validation lives on the server (VariantBuilder): this dialog
 * only shows its preview — which products can join, with which option values,
 * and why the others cannot — and sends the final choice.
 *
 * Products can also be added in the dialog (search by name or number) and
 * dropped from it, so the list-page selection is only the starting point.
 */
Component.register('optiweb-variant-builder-modal', {
    template,

    inject: ['repositoryFactory', 'syncService'],

    emits: ['modal-close', 'variants-created'],

    mixins: [Mixin.getByName('notification')],

    props: {
        products: {
            type: Array,
            required: true,
        },
    },

    data() {
        return {
            mode: 'new',
            groupIds: [],
            parentId: null,
            parentName: '',
            parentNameEdited: false,
            suggestedName: null,
            suggestedNumber: null,
            parentNumber: '',
            parentNumberEdited: false,
            inheritData: false,
            preview: null,
            isLoading: false,
            isCreating: false,
            previewRequest: 0,
            addedIds: [],
            removedIds: [],
            addSelectKey: 0,
        };
    },

    computed: {
        propertyGroupRepository() {
            return this.repositoryFactory.create('property_group');
        },

        httpClient() {
            return Shopware.Application.getContainer('init').httpClient;
        },

        productIds() {
            const ids = [...this.products.map((product) => product.id), ...this.addedIds];

            return [...new Set(ids)].filter((id) => !this.removedIds.includes(id));
        },

        // Standalone products (no parent, no variants) not in the dialog yet.
        addProductCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addFilter(Criteria.equals('parentId', null));
            criteria.addFilter(Criteria.multi('OR', [
                Criteria.equals('childCount', 0),
                Criteria.equals('childCount', null),
            ]));
            const excluded = [...this.productIds, ...(this.parentId ? [this.parentId] : [])];
            if (excluded.length) {
                criteria.addFilter(Criteria.not('AND', [Criteria.equalsAny('id', excluded)]));
            }

            return criteria;
        },

        modeOptions() {
            return [
                { value: 'new', name: this.$t('optiweb-variant-builder.modeNew') },
                { value: 'existing', name: this.$t('optiweb-variant-builder.modeExisting') },
            ];
        },

        parentCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addFilter(Criteria.equals('parentId', null));
            criteria.addFilter(Criteria.range('childCount', { gt: 0 }));
            if (this.productIds.length) {
                criteria.addFilter(Criteria.not('AND', [Criteria.equalsAny('id', this.productIds)]));
            }

            return criteria;
        },

        canCreate() {
            if (!this.preview || this.isLoading || this.isCreating) {
                return false;
            }
            if (this.preview.errors.length > 0 || this.preview.validCount === 0) {
                return false;
            }
            if (this.mode === 'new') {
                return this.parentName.trim() !== '' && this.parentNumber.trim() !== '';
            }

            return this.parentId !== null;
        },
    },

    created() {
        this.loadPreview();
    },

    methods: {
        onModeChange(mode) {
            this.mode = mode;
            this.preview = null;
            this.loadPreview();
        },

        onGroupsChange(groupIds) {
            this.groupIds = groupIds || [];
            this.loadPreview();
        },

        onParentChange(parentId) {
            this.parentId = parentId || null;
            this.loadPreview();
        },

        onAddProduct(productId) {
            if (!productId) {
                return;
            }

            this.removedIds = this.removedIds.filter((id) => id !== productId);
            if (!this.addedIds.includes(productId)) {
                this.addedIds.push(productId);
            }
            // Re-mount the search field so it is empty for the next product.
            this.addSelectKey += 1;
            this.loadPreview();
        },

        onRemoveProduct(productId) {
            this.addedIds = this.addedIds.filter((id) => id !== productId);
            if (!this.removedIds.includes(productId)) {
                this.removedIds.push(productId);
            }
            this.loadPreview();
        },

        // The text fields also emit when the value is set from here, so only a
        // value other than the suggestion counts as the user's own.
        onParentNameInput(value) {
            this.parentName = value;
            this.parentNameEdited = value !== this.suggestedName;
        },

        onParentNumberInput(value) {
            this.parentNumber = value;
            this.parentNumberEdited = value !== this.suggestedNumber;
        },

        // The list is shown from the start; until properties (or a parent) are
        // chosen, its rows are only "pending" and nothing can be created.
        async loadPreview() {
            // Only the answer to the latest request is shown.
            const request = ++this.previewRequest;
            this.isLoading = true;

            try {
                const { data } = await this.httpClient.post(
                    '/_action/optiweb/variant-builder/preview',
                    this.payload(),
                    { headers: this.syncService.getBasicHeaders() },
                );
                if (request !== this.previewRequest) {
                    return;
                }

                this.preview = data;
                if (data.suggestedName && (!this.parentNameEdited || this.parentName.trim() === '')) {
                    this.suggestedName = data.suggestedName;
                    this.parentName = data.suggestedName;
                    this.parentNameEdited = false;
                }
                if (data.suggestedNumber && !this.parentNumberEdited) {
                    this.suggestedNumber = data.suggestedNumber;
                    this.parentNumber = data.suggestedNumber;
                }
            } catch (error) {
                this.createNotificationError({ message: this.apiError(error) });
            } finally {
                if (request === this.previewRequest) {
                    this.isLoading = false;
                }
            }
        },

        async onCreate() {
            this.isCreating = true;

            try {
                const { data } = await this.httpClient.post(
                    '/_action/optiweb/variant-builder/create',
                    {
                        ...this.payload(),
                        parentName: this.parentName.trim(),
                        parentNumber: this.parentNumber.trim(),
                        inheritData: this.inheritData,
                    },
                    { headers: this.syncService.getBasicHeaders() },
                );

                this.createNotificationSuccess({
                    message: this.$t('optiweb-variant-builder.success', { count: data.variantIds.length }),
                });
                this.$emit('variants-created', data.parentId);
            } catch (error) {
                this.createNotificationError({ message: this.apiError(error) });
            } finally {
                this.isCreating = false;
            }
        },

        // "Choose properties" / "choose a parent" is said by the rows already.
        visibleErrors() {
            if (!this.preview) {
                return [];
            }

            return this.preview.errors.filter((error) => !(
                (error.code === 'noGroups' && this.mode === 'new')
                || (this.mode === 'existing' && !this.parentId)
            ));
        },

        pendingText() {
            return this.$t(this.mode === 'new'
                ? 'optiweb-variant-builder.statusPendingGroups'
                : 'optiweb-variant-builder.statusPendingParent');
        },

        payload() {
            return {
                productIds: this.productIds,
                groupIds: this.mode === 'new' ? this.groupIds : [],
                parentId: this.mode === 'existing' ? this.parentId : null,
            };
        },

        optionName(row, groupId) {
            return row.options.find((option) => option.groupId === groupId)?.name || '—';
        },

        errorText(error) {
            return this.$t(`optiweb-variant-builder.errors.${error.code}`, error);
        },

        apiError(error) {
            const apiError = error?.response?.data?.errors?.[0];
            const reason = apiError?.meta?.parameters?.reason;
            if (reason && this.$te(`optiweb-variant-builder.errors.${reason}`)) {
                return this.$t(`optiweb-variant-builder.errors.${reason}`, { minimum: 1 });
            }

            return apiError?.detail || error?.message || this.$t('optiweb-variant-builder.errors.unknown');
        },
    },
});
