import template from './optiweb-notice-bar-index.html.twig';

const { Mixin } = Shopware;

/**
 * Mirrors sw-settings-basic-information: sw-system-config renders the fields from
 * noticeBar.xml (with the sales channel switch) and saveAll() persists them.
 */
Shopware.Component.register('optiweb-notice-bar-index', {
    template,

    inject: ['acl'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    computed: {
        canEdit() {
            return this.acl.can('optiweb_notice_bar.editor');
        },
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        onSave() {
            this.isSaveSuccessful = false;
            this.isLoading = true;

            this.$refs.systemConfig
                .saveAll()
                .then(() => {
                    this.isLoading = false;
                    this.isSaveSuccessful = true;
                })
                .catch((error) => {
                    this.isLoading = false;
                    this.createNotificationError({
                        message: error,
                    });
                });
        },

        onLoadingChanged(loading) {
            this.isLoading = loading;
        },
    },
});
