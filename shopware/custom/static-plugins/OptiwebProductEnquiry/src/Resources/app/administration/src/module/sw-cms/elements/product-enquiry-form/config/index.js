import template from './sw-cms-el-config-product-enquiry-form.html.twig';
import './sw-cms-el-config-product-enquiry-form.scss';

const { Mixin } = Shopware;

Shopware.Component.register('sw-cms-el-config-product-enquiry-form', {
    template,

    mixins: [
        Mixin.getByName('cms-element'),
    ],

    data() {
        return {
            mailReceiverText: '',
        };
    },

    created() {
        this.initElementConfig('product-enquiry-form');
        this.initializeMailReceiverText();
    },

    methods: {
        initializeMailReceiverText() {
            const receivers = this.element.config.mailReceiver?.value ?? [];
            this.mailReceiverText = Array.isArray(receivers) ? receivers.join(', ') : receivers;
        },

        updateMailReceiver() {
            const emails = this.mailReceiverText
                .split(',')
                .map((e) => e.trim())
                .filter((e) => e && this.validateEmail(e));
            this.element.config.mailReceiver.value = emails;
        },

        validateEmail(email) {
            return /^\w+([.\-+]?\w+)*@\w+([.-]?\w+)*(\.\w{2,})+$/.test(email);
        },
    },
});
