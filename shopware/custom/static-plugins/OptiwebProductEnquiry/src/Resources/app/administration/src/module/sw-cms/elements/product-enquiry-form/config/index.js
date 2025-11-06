import template from './sw-cms-el-config-product-enquiry-form.html.twig';

Shopware.Component.register('sw-cms-el-config-product-enquiry-form', {
    template,

    inject: ['systemConfigApiService'],

    mixins: [
        'cms-element'
    ],

    data() {
        return {
            mailReceiverText: ''
        };
    },

    created() {
        this.createdComponent();
        this.initializeMailReceiverText();
    },

    methods: {
        createdComponent() {
            this.initElementConfig('product-enquiry-form');
        },

        initializeMailReceiverText() {
            if (this.element.config.mailReceiver.value && Array.isArray(this.element.config.mailReceiver.value)) {
                this.mailReceiverText = this.element.config.mailReceiver.value.join(', ');
            }
        },

        updateMailReceiver() {
            // Split by comma and clean up whitespace
            const emails = this.mailReceiverText
                .split(',')
                .map(email => email.trim())
                .filter(email => email.length > 0);

            // Validate emails
            const validEmails = emails.filter(email => this.validateEmail(email));
            
            // Update the config
            this.element.config.mailReceiver.value = validEmails;
        },

        validateEmail(email) {
            const mailformat = /^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,3})+$/;
            return email.match(mailformat) !== null;
        }
    }
});
