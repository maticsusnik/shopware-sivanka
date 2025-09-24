import template from './sw-cms-el-config-main-slider.html.twig';
import './sw-cms-el-config-main-slider.scss';

const {Component, Mixin} = Shopware;

Component.register('sw-cms-el-config-main-slider', {
    template,
    inject: ['repositoryFactory'],
    emits: ['element-update'],
    mixins: [
        Mixin.getByName('cms-element')
    ],
    data() {
        return {
            activeSlide: 0,
            mediaModalIsOpen: false,
            initialFolderId: null,
        }
    },
    created() {
        this.createdComponent();
    },
    watch: {
        'element.config.slides': {
            handler() {
                this.element.config.update.value = (Math.random() * Math.floor(Date.now() / 1000)).toString();
            },
            deep: true
        }
    },
    computed: {
        computedSlides() {
            return this.element.config.slides.value;
        },
        //MEDIA
        mediaRepository() {
            return this.repositoryFactory.create('media');
        },
        uploadTag() {
            return `cms-element-main-slider-${this.element.id}`;
        },

        //END MEDIA
    },
    methods: {
        createdComponent() {
            this.initElementConfig('main-slider');
            if (!this.element.config.slides.value.length) {
                this.createNewSlide();
            }
        },
        //MEDIA
        async onImageUpload({ targetId },index) {
            const mediaEntity = await this.mediaRepository.get(targetId);

            this.element.config.slides.value[index].image.value = mediaEntity.id;
            this.element.config.slides.value[index].image.source = 'static';

            this.updateElementData(index, mediaEntity);

            this.$emit('element-update', this.element);
        },

        onImageRemove(index) {
            this.element.config.slides.value[index].image.value = null;

            this.updateElementData(index);

            this.$emit('element-update', this.element);
        },

        onCloseModal() {
            this.mediaModalIsOpen = false;
        },

        onSelectionChanges(mediaEntity,index) {
            const media = mediaEntity[0];
            this.element.config.slides.value[index].image.value = media.id;
            this.element.config.slides.value[index].image.source = 'static';

            this.updateElementData(index, media);

            this.$emit('element-update', this.element);
        },

        updateElementData(index, media = null ) {
            const mediaId = media === null ? null : media.id;
            if (!this.element.config.slides.value[index]) {
                this.element.config.slides.value[index] = { mediaId, media };

                return;
            }

            this.element.config.slides.value[index].mediaId = mediaId;
            this.element.config.slides.value[index].media = media;
        },

        handleButtonInput(index, newValue) {
            if (newValue !== this.element.config.slides.value[index].button) {
                this.element.config.slides.value[index].button = newValue;
                this.$emit('element-update', this.element);
            }
        },

        onOpenMediaModal() {
            this.mediaModalIsOpen = true;
        },

        previewSource(index) {
            // if (this.element.config.slides.value[index]?.media?.id) {
            //     return this.element.config.slides.value[index].media;
            // }
            // if (this.element.data && this.element.data.image && this.element.data.image.id) {
            //     return this.element.data.image;
            // }

            // return this.element.config.slides.value[index].image.value;
            // return this.element.config.slides?.value?.[index]?.image.value;
            return this.element.config.slides?.value?.[index]?.image.value || null;

        },

        //END MEDIA
        createNewSlide() {
            if (this.element.config.slides.value.length >= 10) return;
            const slide = this.newSlideTemplate();
            this.element.config.slides.value.push(slide);
        },
        removeSlide(index) {
            this.element.config.slides.value.splice(index, 1);
            this.activeSlide = 0;
            this.$refs.tab0[0].clickEvent();
        },
        newSlideTemplate() {
            const slide = {
                image: {
                    value: null,
                    source: null,
                },
                media:null,
                mediaId:null,
                aboveTitle: '',
                title: '',
                description: '',
                button: null,
                order: 0
            };
            return {...slide};
        }
    }
});
