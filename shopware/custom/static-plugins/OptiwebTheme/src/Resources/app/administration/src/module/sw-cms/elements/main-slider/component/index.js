/* global Shopware */

import template from './sw-cms-el-main-slider.html.twig';
import './sw-cms-el-main-slider.scss';


const {Component, Mixin} = Shopware;

Component.register('sw-cms-el-main-slider', {
    template,
    mixins: [
        Mixin.getByName('cms-element')
    ],
    inject: ['repositoryFactory'],
    data() {
        return {
            tag: '',
            slides: []
        }
    },
    created() {
        this.createdComponent();
    },
    watch: {
        'element.config.update.value': {
            handler() {
                this.updateSlides();
            }
        }
    },
    computed: {
        sortedSlides() {
            return this.slides.slice().sort((slide1, slide2) => parseInt(slide1.order) - parseInt(slide2.order));
        }
    },
    methods: {
        getSlideBackground(slide) {
            if (!slide.media) return {};
            if (this.isUrl(slide.media?.url)) {
                return {
                    backgroundImage: `url('${slide.media.url}')`
                }
            }
            return {};
        },
        createdComponent() {
            this.initElementConfig('main-slider');
            if (this.isBoilerPlate(this.element.config.slides.value)) return;
            this.updateSlides();
        },

        /**
         * This function has to be changed if default slider data will change.
         * This function checks if first slide is empty.
         */
        isBoilerPlate(slides) {
            if (!slides.length) return true;
            if (slides.length > 1) return false;
            for (let prop in slides[0]) {
                if (slides[0].hasOwnProperty(prop)) {
                    if (slides[0][prop]) return false;
                }
            }
            return true;
        },
        updateSlides() {
            this.slides = this.element.config.slides.value;
        },
        isUrl(url) {
            if (!url) return false;
            return url.indexOf("http") >= 0;
        }
    }
});
