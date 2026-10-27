import template from './optiweb-variant-builder-modal.html.twig';
import './optiweb-variant-builder-modal.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Turns the products selected in the product list into variants of a new or an
 * existing parent. Validation lives on the server (VariantBuilder): this dialog
 * only shows its preview — which products can join, with which option values,
 * and why the others cannot — and sends the final choice.
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
            parentNumber: '',
            parentNumberEdited: false,
            inheritData: false,
            preview: null,
            isLoading: false,
            isCreating: false,
            previewRequest: 0,
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
            return this.products.map((product) => product.id);
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

        onParentNumberInput(value) {
            this.parentNumber = value;
            this.parentNumberEdited = true;
        },

        async loadPreview() {
            if (this.mode === 'new' && this.groupIds.length === 0) {
                this.preview = null;

                return;
            }
            if (this.mode === 'existing' && !this.parentId) {
                this.preview = null;

                return;
            }

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
                if (data.suggestedName && this.parentName.trim() === '') {
                    this.parentName = data.suggestedName;
                }
                if (data.suggestedNumber && !this.parentNumberEdited) {
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
