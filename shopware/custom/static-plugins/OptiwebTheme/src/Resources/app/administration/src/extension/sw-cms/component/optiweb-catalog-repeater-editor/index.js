import template from './optiweb-catalog-repeater-editor.html.twig';
import './optiweb-catalog-repeater-editor.scss';

const {Component, Store} = Shopware;

Component.register('optiweb-catalog-repeater-editor', {
    template,
    inject: ['repositoryFactory'],
    props: {
        modelValue: {
            type: Array,
            required: false,
            default: () => []
        }
    },

    data() {
        return {
            catalogs: this.modelValue,
            activeCatalogForMediaModal: null,
            activeCatalogForDocumentModal: null,
            nextCatalogId: 0,
            linkTypes: [
                {
                    id: 1,
                    label: "Upload Document",
                    value: 'document'
                },
                {
                    id: 2,
                    label: "External Link",
                    value: 'url'
                }
            ]
        };
    },

    watch: {
        value(newValue) {
            this.catalogs = newValue;
        }
    },

    computed: {
        //MEDIA
        cmsPageState() {
            return Store.get('cmsPage');
        },
        mediaRepository() {
            return this.repositoryFactory.create('media');
        },
        //END MEDIA
    },
    created() {
        if (this.catalogs && this.catalogs.length > 0) {
            let maxId = 0;
            this.catalogs.forEach(catalog => {
                if (!catalog.id) {
                    catalog.id = this.nextCatalogId++;
                }
                if (catalog.id > maxId) {
                    maxId = catalog.id;
                }
            });
            this.nextCatalogId = maxId + 1;
        }
    },

    methods: {
        addCatalog() {
            // Get the highest priority from the existing catalogs, or 0 if there are no catalogs
            let maxPriority = 0;
            if (this.catalogs && this.catalogs.length > 0) {
                maxPriority = Math.max(...this.catalogs.map(catalog => catalog.priority || 0));
            }

            this.catalogs.unshift({
                id: this.nextCatalogId++,
                image: {
                    value: null,
                    source: null,
                },
                media: null,
                mediaId: null,
                document: {
                    value: null,
                    source: null,
                },
                documentMedia: null,
                documentMediaId: null,
                url: null,
                title: '',
                priority: maxPriority + 1,
                linkType: 'document'
            });

            this.catalogs.sort((a, b) => b.priority - a.priority); // Sort the catalogs by priority in descending order

            this.updateValue();
        },

        removeCatalog(index) {
            this.catalogs.splice(index, 1);
            this.catalogs.sort((a, b) => b.priority - a.priority);
            this.updateValue();
        },

        sortCatalogs() {
            this.catalogs.sort((a, b) => b.priority - a.priority);
            this.catalogs = [...this.catalogs];
            this.updateValue();
        },

        getCatalog(id) {
            return this.catalogs.find(cat => cat.id === id);
        },

        getCatalogIndex(id) {
            return this.catalogs.findIndex(cat => cat.id === id);
        },

        updateValue() {
            this.$emit('update', this.catalogs);
        },

        // MEDIA
        getUploadTag(catalogId, mediaType) {
            return `optiweb-catalog-repeater-${mediaType}-${catalogId}`;
        },

        async onMediaUpload({ targetId }, catalogId, mediaType) {
            const mediaEntity = await this.mediaRepository.get(targetId);
            const index = this.getCatalogIndex(catalogId);
            this.updateCatalogMediaData(index, mediaType, mediaEntity);
            this.$emit('element-update', this.element);
        },

        onMediaRemove(catalogId, mediaType) {
            const index = this.getCatalogIndex(catalogId);
            this.updateCatalogMediaData(index, mediaType, null);
            this.$emit('element-update', this.element);
        },

        onMediaSelectionChanges(selection, catalogId, mediaType) {
            const media = selection[0];
            const index = this.getCatalogIndex(catalogId);
            this.updateCatalogMediaData(index, mediaType, media);
            this.$emit('element-update', this.element);
        },

        onOpenAnyMediaModal(catalogId, mediaType) {
            if (mediaType === 'image') {
                this.activeCatalogForMediaModal = catalogId;
                this.activeCatalogForDocumentModal = null;
            } else if (mediaType === 'document') {
                this.activeCatalogForDocumentModal = catalogId;
                this.activeCatalogForMediaModal = null;
            }
        },

        onCloseAnyMediaModal(mediaType) {
            if (mediaType === 'image') {
            this.activeCatalogForMediaModal = null;
            } else if (mediaType === 'document') {
                this.activeCatalogForDocumentModal = null;
            }
        },

        updateCatalogMediaData(index, mediaType, media = null) {
            const mediaId = media === null ? null : media.id;
            const catalog = this.catalogs[index];

            if (!catalog) {
                return;
            }

            if (mediaType === 'image') {
                catalog.image.value = mediaId;
                catalog.image.source = mediaId ? 'static' : null;
                catalog.mediaId = mediaId;
                catalog.media = media;
            } else if (mediaType === 'document') {
                catalog.document.value = mediaId;
                catalog.document.source = mediaId ? 'static' : null;
                catalog.documentMediaId = mediaId;
                catalog.documentMedia = media;
            }
        },

        getPreviewSource(catalogId, mediaType) {
            const catalog = this.getCatalog(catalogId);
            if (!catalog) return null;

            if (mediaType === 'image') {
                return catalog.image.value || null;
            } else if (mediaType === 'document') {
                return catalog.document.value || null;
            }
            return null;
        },
        // END MEDIA


        onLinkTypeChange(catalog, newType) {
            if (newType === 'document') {
                catalog.url = null;
            } else if (newType === 'url') {
                catalog.document = { value: null, source: null };
                catalog.documentMedia = null;
                catalog.documentMediaId = null;
            }
            this.updateValue();
        }
    }
});