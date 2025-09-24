import Plugin from 'src/plugin-system/plugin.class';
import ViewportDetection from 'src/helper/viewport-detection.helper';
import Debouncer from 'src/helper/debouncer.helper';


export default class StickyBuyBox extends Plugin {

    static options = {
        stickyOnViewPorts: ['LG', 'XL', 'XXL']
    };

    init() {
        this.toggleStickyBuyBox();
        window.addEventListener('resize', Debouncer.debounce(this.toggleStickyBuyBox.bind(this), 100), {capture: true, passive: true});

        this.setStickyBuyBox();
        window.addEventListener('scroll', this.setStickyBuyBox.bind(this));
    }

    /**
     * Only enable sticky buy box on desktop
     */
    toggleStickyBuyBox() {
        const enabledStickyBuyOld = this.enabledStickyBuy;
        this.enabledStickyBuy = this.options.stickyOnViewPorts.includes(ViewportDetection.getCurrentViewport());

        if (enabledStickyBuyOld !== this.enabledStickyBuy) {
            this.setStickyBuyBox(); //re-calculate the buy box state if sticky status is toggled
        }
    }

    /**
     * set different states of buy box
     */
    setStickyBuyBox() {
        if (!this.enabledStickyBuy) {
            //disabled state
            this.el.style.position = 'static';
            this.el.style.width = '100%';
            return;
        }

        const container = document.querySelector('.product-page-top-section'),
            containerRect = container.getBoundingClientRect(),
            elementHeight = this.el.offsetHeight,
            bottomMargin = 80,
            bottomPosition = containerRect.bottom - bottomMargin - elementHeight;

        //force buy box width to match its parent width (for fixed position)
        this.el.style.width = `${this.el.parentElement.offsetWidth}px`;

        if (containerRect.top <= 0 && bottomPosition >= 0) {
            //scrolling state
            this.el.style.position = 'fixed';
            this.el.style.top = '0';
        } else if (bottomPosition < 0) {
            //bottom state
            this.el.style.position = 'absolute';
            this.el.style.top = `${container.offsetHeight - bottomMargin - elementHeight}px`;
        } else {
            //top state
            this.el.style.position = 'static';
        }
    }

}
