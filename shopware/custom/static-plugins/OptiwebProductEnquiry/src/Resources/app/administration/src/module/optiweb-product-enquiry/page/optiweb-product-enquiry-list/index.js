import { ref, computed } from 'vue';
import template from './optiweb-product-enquiry-list.html.twig';
import './optiweb-product-enquiry-list.scss';

const { Criteria } = Shopware.Data;

Shopware.Component.register('optiweb-product-enquiry-list', {
    template,

    inject: ['repositoryFactory'],

    setup() {
        const enquiries = ref([]);
        const isLoading = ref(true);
        const total = ref(0);
        const page = ref(1);
        const limit = ref(25);
        const sortBy = ref('createdAt');
        const sortDirection = ref('DESC');
        const searchTerm = ref('');
        const unreadCount = computed(() => enquiries.value.filter((e) => e.status === 'new').length);

        return { enquiries, isLoading, total, page, limit, sortBy, sortDirection, searchTerm, unreadCount };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('product_enquiry');
        },

        criteria() {
            const criteria = new Criteria(this.page, this.limit);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));
            if (this.searchTerm) {
                criteria.addFilter(Criteria.multi('OR', [
                    Criteria.contains('email', this.searchTerm),
                    Criteria.contains('firstName', this.searchTerm),
                    Criteria.contains('lastName', this.searchTerm),
                ]));
            }
            return criteria;
        },
    },

    created() {
        this.loadEnquiries();
    },

    methods: {
        async loadEnquiries() {
            this.isLoading = true;
            const result = await this.repository.search(this.criteria, Shopware.Context.api);
            this.enquiries = result;
            this.total = result.total;
            this.isLoading = false;
        },

        onRowClick(item) {
            this.$router.push({ name: 'optiweb.product.enquiry.detail', params: { id: item.id } });
        },

        onSearch(term) {
            this.searchTerm = term;
            this.page = 1;
            this.loadEnquiries();
        },

        formatDate(value) {
            if (!value) return '';
            return new Date(value).toLocaleString('sl-SI', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
            });
        },
    },
});
