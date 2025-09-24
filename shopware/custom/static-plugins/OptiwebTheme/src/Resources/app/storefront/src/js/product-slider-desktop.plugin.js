import Plugin from 'src/plugin-system/plugin.class';
import ViewportDetection from 'src/helper/viewport-detection.helper';
export default class ProductSliderDesktopPlugin extends Plugin {

    static options = {
        disabledOnViewPorts: ['SM', 'XS'],
    }

    init() {
        this.handleResize();
        document.addEventListener('Viewport/hasChanged', this.handleResize.bind(this));
    }

    handleResize() {
        const elements = document.querySelectorAll('[data-product-slider-desktop]'),
            viewport = ViewportDetection.getCurrentViewport(),
            shouldShowSlider = !this.options.disabledOnViewPorts.find(vp => vp === viewport);

        elements.forEach(element => {
            let productSlider = window.PluginManager.getPluginInstanceFromElement(element, 'ProductSlider');

            if (shouldShowSlider) {
                element.setAttribute('data-product-slider', 'true');
                window.PluginManager.initializePlugin('ProductSlider', '[data-product-slider]');
            }
            if (!shouldShowSlider && productSlider) {
                element.removeAttribute('data-product-slider');
                productSlider?.destroy();
            }

        })

    }
}
