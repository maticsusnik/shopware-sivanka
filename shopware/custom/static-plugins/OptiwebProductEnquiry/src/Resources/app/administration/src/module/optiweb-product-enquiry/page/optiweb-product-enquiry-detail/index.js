import { ref } from 'vue';

const { Criteria } = Shopware.Data;
import template from './optiweb-product-enquiry-detail.html.twig';
import './optiweb-product-enquiry-detail.scss';

Shopware.Component.register('optiweb-product-enquiry-detail', {
    template,

    inject: ['repositoryFactory'],

    mixins: [Shopware.Mixin.getByName('notification')],

    props: {
        enquiryId: {
            type: String,
            required: true,
        },
    },

    setup() {
        const enquiry = ref(null);
        const isLoading = ref(true);
        const isSaving = ref(false);
        const showDeleteModal = ref(false);

        return { enquiry, isLoading, isSaving, showDeleteModal };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('product_enquiry');
        },

        isDone() {
            return this.enquiry?.status === 'done';
        },

        /**
         * Rows for the product table.
         *
         * Falls back to the enquiry's own product columns for rows written before
         * line items existed and never backfilled — the grid should show the one
         * product rather than nothing at all.
         */
        productLines() {
            if (!this.enquiry) {
                return [];
            }

            const lines = this.enquiry.lines;

            if (lines && lines.length > 0) {
                return [...lines].sort((a, b) => a.position - b.position);
            }

            return [{
                id: this.enquiry.id,
                productName: this.enquiry.productName,
                productNumber: this.enquiry.productNumber,
                productOption: this.enquiry.productOption,
                quantity: this.enquiry.quantity,
            }];
        },

        // Replaces the Vue 2 `| date(...)` template filter, which Vue 3 no longer parses.
        formattedCreatedAt() {
            if (!this.enquiry) {
                return '';
            }

            return Shopware.Filter.getByName('date')(this.enquiry.createdAt, { hour: '2-digit', minute: '2-digit' });
        },

        productColumns() {
            return [
                { property: 'productName', label: this.$t('optiwebProductEnquiry.admin.detail.columnProduct'), rawData: true },
                { property: 'productNumber', label: this.$t('optiwebProductEnquiry.admin.detail.columnNumber'), rawData: true },
                { property: 'productOption', label: this.$t('optiwebProductEnquiry.admin.detail.columnVariant'), rawData: true },
                { property: 'quantity', label: this.$t('optiwebProductEnquiry.admin.detail.columnQuantity'), rawData: true, align: 'right' },
            ];
        },
    },

    created() {
        this.loadEnquiry();
    },

    methods: {
        async loadEnquiry() {
            this.isLoading = true;

            const criteria = new Criteria();
            criteria.addAssociation('lines');

            this.enquiry = await this.repository.get(this.enquiryId, Shopware.Context.api, criteria);
            this.isLoading = false;
        },

        async toggleDone() {
            const previous = this.enquiry.status;
            // Reopening goes back to read, not new: someone has already handled it once
            this.enquiry.status = this.isDone ? 'read' : 'done';
            this.isSaving = true;

            try {
                await this.repository.save(this.enquiry, Shopware.Context.api);
            } catch {
                this.enquiry.status = previous;
                this.createNotificationError({ message: this.$t('optiwebProductEnquiry.admin.notification.saveError') });
            }

            this.isSaving = false;
        },

        async onConfirmDelete() {
            this.showDeleteModal = false;
            this.isSaving = true;

            try {
                await this.repository.delete(this.enquiry.id, Shopware.Context.api);
                this.createNotificationSuccess({
                    message: this.$t('optiwebProductEnquiry.admin.notification.deleted', { count: 1 }, 1),
                });
                this.goBack();
            } catch {
                this.isSaving = false;
                this.createNotificationError({ message: this.$t('optiwebProductEnquiry.admin.notification.deleteError') });
            }
        },

        goBack() {
            this.$router.push({ name: 'optiweb.product.enquiry.list' });
        },
    },
});
