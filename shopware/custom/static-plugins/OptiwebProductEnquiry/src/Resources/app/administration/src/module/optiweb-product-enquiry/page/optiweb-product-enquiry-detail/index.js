import { ref } from 'vue';
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
    },

    created() {
        this.loadEnquiry();
    },

    methods: {
        async loadEnquiry() {
            this.isLoading = true;
            this.enquiry = await this.repository.get(this.enquiryId, Shopware.Context.api);
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
