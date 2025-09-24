/* global Shopware */

import template from './sw-cms-el-image-tab.html.twig';
import './sw-cms-el-image-tab.scss';


const {Component, Mixin} = Shopware;

Component.register('sw-cms-el-image-tab', {
    template,
    mixins: [
        Mixin.getByName('cms-element')
    ],
    inject: ['repositoryFactory'],
    data() {
        return {
            tag: '',
            tabs: []
        }
    },
    created() {
        this.createdComponent();
    },
    watch: {
        'element.config.update.value': {
            handler() {
                this.updateTabs();
            }
        }
    },
    computed: {
        sortedTabs() {
            return this.tabs.slice().sort((tab1, tab2) => parseInt(tab1.order) - parseInt(tab2.order));
        }
    },
    methods: {
        getTabBackground(tab) {
            if (!tab.media) return {};
            if (this.isUrl(tab.media?.url)) {
                return {
                    backgroundImage: `url('${tab.media.url}')`
                }
            }
            return {};
        },
        createdComponent() {
            this.initElementConfig('image-tab');
            if (this.isBoilerPlate(this.element.config.tabs.value)) return;
            this.updateTabs();
        },

        /**
         * This function has to be changed if default tabr data will change.
         * This function checks if first tab is empty.
         */
        isBoilerPlate(tabs) {
            if (!tabs.length) return true;
            if (tabs.length > 1) return false;
            for (let prop in tabs[0]) {
                if (tabs[0].hasOwnProperty(prop)) {
                    if (tabs[0][prop]) return false;
                }
            }
            return true;
        },
        updateTabs() {
            this.tabs = this.element.config.tabs.value;
        },
        isUrl(url) {
            if (!url) return false;
            return url.indexOf("http") >= 0;
        }
    }
});
