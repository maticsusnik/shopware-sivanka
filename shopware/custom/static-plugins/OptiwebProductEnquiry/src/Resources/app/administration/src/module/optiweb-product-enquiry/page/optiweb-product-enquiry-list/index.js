import { ref } from 'vue';
import template from './optiweb-product-enquiry-list.html.twig';
import './optiweb-product-enquiry-list.scss';

const { Criteria } = Shopware.Data;

const STATUS_DONE = 'done';
// An enquiry that is "un-done" goes back to read, not new: someone has seen it
const STATUS_OPEN = 'read';

Shopware.Component.register('optiweb-product-enquiry-list', {
    template,

    inject: ['repositoryFactory'],

    mixins: [Shopware.Mixin.getByName('notification')],

    setup() {
        const enquiries = ref([]);
        const isLoading = ref(true);
        const total = ref(0);
        const page = ref(1);
        const limit = ref(25);
        const sortBy = ref('createdAt');
        const sortDirection = ref('DESC');
        const searchTerm = ref('');
        const selection = ref({});
        // Ids waiting for delete confirmation; null when no modal is open
        const deleteIds = ref(null);

        return { enquiries, isLoading, total, page, limit, sortBy, sortDirection, searchTerm, selection, deleteIds };
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

        columns() {
            return [
                { property: 'status', label: this.$t('optiwebProductEnquiry.admin.list.columns.done'), sortable: true, width: '80px', align: 'center' },
                { property: 'createdAt', label: this.$t('optiwebProductEnquiry.admin.list.columns.date'), sortable: true },
                { property: 'firstName', label: this.$t('optiwebProductEnquiry.admin.list.columns.customer'), sortable: true },
                { property: 'email', label: this.$t('optiwebProductEnquiry.admin.list.columns.email'), sortable: true },
                { property: 'productName', label: this.$t('optiwebProductEnquiry.admin.list.columns.product') },
                { property: 'quantity', label: this.$t('optiwebProductEnquiry.admin.list.columns.quantity') },
            ];
        },

        selectionCount() {
            return Object.keys(this.selection).length;
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

        onColumnSort(column) {
            if (this.sortBy === column.property) {
                this.sortDirection = this.sortDirection === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.sortBy = column.property;
                this.sortDirection = 'ASC';
            }
            this.loadEnquiries();
        },

        onPageChange({ page, limit }) {
            this.page = page;
            this.limit = limit;
            this.loadEnquiries();
        },

        onSelectionChange(selection) {
            this.selection = selection;
        },

        isDone(item) {
            return item.status === STATUS_DONE;
        },

        async setDone(item, done) {
            const previous = item.status;
            item.status = done ? STATUS_DONE : STATUS_OPEN;

            try {
                await this.repository.save(item, Shopware.Context.api);
            } catch {
                item.status = previous;
                this.createNotificationError({ message: this.$t('optiwebProductEnquiry.admin.notification.saveError') });
            }
        },

        onDelete(ids) {
            this.deleteIds = ids;
        },

        onCloseDeleteModal() {
            this.deleteIds = null;
        },

        async onConfirmDelete() {
            const ids = this.deleteIds;
            this.deleteIds = null;
            this.isLoading = true;

            try {
                await this.repository.syncDeleted(ids, Shopware.Context.api);
                this.createNotificationSuccess({
                    message: this.$t('optiwebProductEnquiry.admin.notification.deleted', { count: ids.length }, ids.length),
                });
            } catch {
                this.createNotificationError({ message: this.$t('optiwebProductEnquiry.admin.notification.deleteError') });
            }

            this.selection = {};
            this.$refs.grid?.resetSelection();
            // Step back a page when the last row of the last page was removed
            if (this.page > 1 && (this.page - 1) * this.limit >= this.total - ids.length) {
                this.page -= 1;
            }
            await this.loadEnquiries();
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
