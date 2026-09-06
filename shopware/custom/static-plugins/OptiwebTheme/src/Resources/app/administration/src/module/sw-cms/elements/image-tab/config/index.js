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
        // `mt-tabs` is driven by an items array; the deprecated `sw-tabs` +
        // `sw-tabs-item` slot form is dropped in 6.8.
        tabItems() {
            return (this.computedTabs || []).map((item, index) => ({
                name: `tab-${index}`,
                label: `Tab ${index + 1}`,
            }));
        },

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
        onTabChange(name) {
            this.activeTab = Number(String(name).replace('tab-', '')) || 0;
        },

        createdComponent() {
            this.initElementConfig('image-tab');

            // Titles saved before this config used a plain string are `{ text: '...' }`,
            // which a text field would show as "[object Object]".
            const title = this.element.config.title.value;
            if (title && typeof title === 'object') {
                this.element.config.title.value = title.text ?? '';
            }

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
            this.activeTab = Math.max(0, Math.min(this.activeTab, this.element.config.tabs.value.length - 1));
            this.$emit('element-update', this.element);
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
