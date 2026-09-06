import template from './sw-cms-el-sivanka-hero.html.twig';
import './sw-cms-el-sivanka-hero.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-cms-el-sivanka-hero', {
    template,

    inject: ['repositoryFactory'],

    mixins: [Mixin.getByName('cms-element')],

    data() {
        return {
            // Loaded here rather than read from `element.data`: nothing populates that on a
            // plain page load (the PHP resolver only runs for the storefront), so the stage
            // preview would otherwise never show the configured photographs.
            mediaLeft: null,
            mediaRight: null,
        };
    },

    computed: {
        mediaRepository() {
            return this.repositoryFactory.create('media');
        },

        imageLeftId() {
            return this.element?.config?.imageLeft?.value ?? null;
        },

        imageRightId() {
            return this.element?.config?.imageRight?.value ?? null;
        },

        headlineLines() {
            return String(this.element?.config?.headline?.value ?? '').split('\n');
        },

        trustItems() {
            const config = this.element.config;

            return [1, 2, 3, 4]
                .map((i) => ({
                    title: config[`trust${i}Title`]?.value,
                    sub: config[`trust${i}Sub`]?.value,
                }))
                .filter((item) => item.title);
        },
    },

    watch: {
        imageLeftId: {
            immediate: true,
            handler(id) {
                this.load(id, 'mediaLeft');
            },
        },

        imageRightId: {
            immediate: true,
            handler(id) {
                this.load(id, 'mediaRight');
            },
        },
    },

    created() {
        this.initElementConfig('sivanka-hero');
    },

    methods: {
        async load(id, target) {
            if (!id) {
                this[target] = null;

                return;
            }

            try {
                this[target] = await this.mediaRepository.get(id, Shopware.Context.api);
            } catch {
                this[target] = null;
            }
        },
    },
});
