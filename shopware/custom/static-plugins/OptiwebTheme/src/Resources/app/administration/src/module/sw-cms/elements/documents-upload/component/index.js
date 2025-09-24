/* global Shopware */

import template from './sw-cms-el-documents-upload.html.twig';
import './sw-cms-el-documents-upload.scss';


const {Component, Mixin} = Shopware;

Component.register('sw-cms-el-documents-upload', {
    template,
    mixins: [
        Mixin.getByName('cms-element')
    ],
    inject: ['repositoryFactory'],
    data() {
        return {
            tag: '',
            documents: []
        }
    },
    created() {
        this.createdComponent();
    },
    watch: {
        'element.config.update.value': {
            handler() {
                this.updateDocuments();
            }
        }
    },
    computed: {
        sortedDocuments() {
            return this.documents
                .filter((document) => document.title) // Remove documents with empty title
                .slice()
                .sort((document1, document2) => parseInt(document1.order) - parseInt(document2.order));

        }
    },
    methods: {
        createdComponent() {
            this.initElementConfig('documents-upload');
            if (this.isBoilerPlate(this.element.config.documents.value)) return;
            this.updateDocuments();
        },

        /**
         * This function has to be changed if default documents data will change.
         * This function checks if first document is empty.
         */
        isBoilerPlate(documents) {
            if (!documents.length) return true;
            if (documents.length > 1) return false;
            for (let prop in documents[0]) {
                if (documents[0].hasOwnProperty(prop)) {
                    if (documents[0][prop]) return false;
                }
            }
            return true;
        },
        updateDocuments() {
            this.documents = this.element.config.documents.value;
        },
        isUrl(url) {
            if (!url) return false;
            return url.indexOf("http") >= 0;
        }
    }
});
