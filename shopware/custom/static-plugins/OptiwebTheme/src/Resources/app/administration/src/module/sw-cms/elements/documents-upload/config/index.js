import template from './sw-cms-el-config-documents-upload.html.twig';
import './sw-cms-el-config-documents-upload.scss';

const {Component, Mixin} = Shopware;

Component.register('sw-cms-el-config-documents-upload', {
    template,
    inject: ['repositoryFactory'],
    emits: ['element-update'],
    mixins: [
        Mixin.getByName('cms-element')
    ],
    data() {
        return {
            activeDocument: 0,
            mediaModalIsOpen: false,
            initialFolderId: null,
        }
    },
    created() {
        this.createdComponent();
    },
    watch: {
        'element.config.documents': {
            handler() {
                this.element.config.update.value = (Math.random() * Math.floor(Date.now() / 1000)).toString();
            },
            deep: true
        }
    },
    computed: {
        computedDocuments() {
            return this.element.config.documents.value;
        },
        //MEDIA
        mediaRepository() {
            return this.repositoryFactory.create('media');
        },
        uploadTag() {
            return `cms-element-documents-upload-${this.element.id}`;
        },

        //END MEDIA
    },
    methods: {
        createdComponent() {
            this.initElementConfig('documents-upload');
            if (!this.element.config.documents.value.length) {
                this.createNewDocument();
            }
        },
        //MEDIA
        async onDocumentUpload({ targetId },index) {
            const mediaEntity = await this.mediaRepository.get(targetId);

            this.element.config.documents.value[index].document.value = mediaEntity.id;
            this.element.config.documents.value[index].document.source = 'static';

            this.updateElementData(index, mediaEntity);

            this.$emit('element-update', this.element);
        },

        onDocumentRemove(index) {
            this.element.config.documents.value[index].document.value = null;

            this.updateElementData(index);

            this.$emit('element-update', this.element);
        },

        onCloseModal() {
            this.mediaModalIsOpen = false;
        },

        onSelectionChanges(mediaEntity,index) {
            const media = mediaEntity[0];
            this.element.config.documents.value[index].document.value = media.id;
            this.element.config.documents.value[index].document.source = 'static';

            this.updateElementData(index, media);

            this.$emit('element-update', this.element);
        },

        updateElementData(index, media = null ) {
            const mediaId = media === null ? null : media.id;
            if (!this.element.config.documents.value[index]) {
                this.element.config.documents.value[index] = { mediaId, media };

                return;
            }

            this.element.config.documents.value[index].mediaId = mediaId;
            this.element.config.documents.value[index].media = media;
        },

        onOpenMediaModal() {
            this.mediaModalIsOpen = true;
        },

        previewSource(index) {
            return this.element.config.documents?.value?.[index]?.document.value || null;

        },

        //END MEDIA
        createNewDocument() {
            if (this.element.config.documents.value.length >= 10) return;
            const document = this.newDocumentTemplate();
            this.element.config.documents.value.push(document);
        },
        removeDocument(index) {
            this.element.config.documents.value.splice(index, 1);
            this.activeDocument = 0;
            this.$refs.tab0[0].clickEvent();
        },
        newDocumentTemplate() {
            const document = {
                document: {
                    value: null,
                    source: null,
                },
                media:null,
                mediaId:null,
                title: '',
                order: 0
            };
            return {...document};
        }
    }
});
