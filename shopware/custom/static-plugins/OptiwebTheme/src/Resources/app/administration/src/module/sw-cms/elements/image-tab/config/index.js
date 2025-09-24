import template from './sw-cms-el-config-image-tab.html.twig';
import './sw-cms-el-config-image-tab.scss';

const {Component, Mixin} = Shopware;

Component.register('sw-cms-el-config-image-tab', {
    template,
    inject: ['repositoryFactory'],
    emits: ['element-update'],
    mixins: [
        Mixin.getByName('cms-element')
    ],
    data() {
        return {
            activeTab: 0,
            mediaModalIsOpen: false,
            initialFolderId: null,
        }
    },
    created() {
        this.createdComponent();
    },
    watch: {
        'element.config.tabs': {
            handler() {
                this.element.config.update.value = (Math.random() * Math.floor(Date.now() / 1000)).toString();
            },
            deep: true
        }
    },
    computed: {
        computedTabs() {
            return this.element.config.tabs.value;
        },
        //MEDIA
        mediaRepository() {
            return this.repositoryFactory.create('media');
        },
        uploadTag() {
            return `cms-element-image-tab-${this.element.id}`;
        },

        //END MEDIA
    },
    methods: {
        createdComponent() {
            this.initElementConfig('image-tab');
            if (!this.element.config.tabs.value.length) {
                this.createNewTab();
            }
        },
        //MEDIA
        async onImageUpload({ targetId },index) {
            const mediaEntity = await this.mediaRepository.get(targetId);

            this.element.config.tabs.value[index].image.value = mediaEntity.id;
            this.element.config.tabs.value[index].image.source = 'static';

            this.updateElementData(index, mediaEntity);

            this.$emit('element-update', this.element);
        },

        onImageRemove(index) {
            this.element.config.tabs.value[index].image.value = null;

            this.updateElementData(index);

            this.$emit('element-update', this.element);
        },

        onCloseModal() {
            this.mediaModalIsOpen = false;
        },

        onSelectionChanges(mediaEntity,index) {
            const media = mediaEntity[0];
            this.element.config.tabs.value[index].image.value = media.id;
            this.element.config.tabs.value[index].image.source = 'static';

            this.updateElementData(index, media);

            this.$emit('element-update', this.element);
        },

        updateElementData(index, media = null ) {
            const mediaId = media === null ? null : media.id;
            if (!this.element.config.tabs.value[index]) {
                this.element.config.tabs.value[index] = { mediaId, media };

                return;
            }

            this.element.config.tabs.value[index].mediaId = mediaId;
            this.element.config.tabs.value[index].media = media;
        },

        onOpenMediaModal() {
            this.mediaModalIsOpen = true;
        },

        previewSource(index) {
            return this.element.config.tabs?.value?.[index]?.image.value || null;

        },

        //END MEDIA
        createNewTab() {
            if (this.element.config.tabs.value.length >= 10) return;
            const tab = this.newTabTemplate();
            this.element.config.tabs.value.push(tab);
        },
        removeTab(index) {
            this.element.config.tabs.value.splice(index, 1);
            this.activeTab = 0;
            this.$refs.tab0[0].clickEvent();
        },
        newTabTemplate() {
            const tab = {
                image: {
                    value: null,
                    source: null,
                },
                media:null,
                mediaId:null,
                button: null,
                order: 0
            };
            return {...tab};
        }
    }
});
