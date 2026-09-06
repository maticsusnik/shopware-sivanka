import { ref } from 'vue';

const { Criteria } = Shopware.Data;
import template from './optiweb-product-enquiry-detail.html.twig';
import './optiweb-product-enquiry-detail.scss';

Shopware.Component.register('optiweb-product-enquiry-detail', {
    template,

    inject: ['repositoryFactory'],

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

        return { enquiry, isLoading, isSaving };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('product_enquiry');
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

        productColumns() {
            return [
                { property: 'productName', label: this.$tc('optiwebProductEnquiry.admin.detail.columnProduct'), rawData: true },
                { property: 'productNumber', label: this.$tc('optiwebProductEnquiry.admin.detail.columnNumber'), rawData: true },
                { property: 'productOption', label: this.$tc('optiwebProductEnquiry.admin.detail.columnVariant'), rawData: true },
                { property: 'quantity', label: this.$tc('optiwebProductEnquiry.admin.detail.columnQuantity'), rawData: true, align: 'right' },
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

        async markAsRead() {
            if (!this.enquiry) return;
            this.isSaving = true;
            this.enquiry.status = 'read';
            await this.repository.save(this.enquiry, Shopware.Context.api);
            this.isSaving = false;
        },

        goBack() {
            this.$router.push({ name: 'optiweb.product.enquiry.list' });
        },
    },
});
