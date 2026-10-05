import OffCanvas from 'src/plugin/offcanvas/offcanvas.plugin';
import OffCanvasFilter from 'src/plugin/offcanvas-filter/offcanvas-filter.plugin';

// Namespaced so unsubscribing only drops this plugin's listener: since 6.7.x the emitter
// ignores a callback argument and would remove every `onCloseOffcanvas` listener.
const CLOSE_EVENT = 'onCloseOffcanvas.OffcanvasCustom';

export default class OffcanvasCustomPlugin extends OffCanvasFilter {

    init() {
        this.targetElementId = this.el.getAttribute('data-off-canvas-custom-content');

        super.init();
    }

    _onCloseOffCanvas(event) {
        const oldChildNode = event.detail.offCanvasContent[0];

        const filterContent = document.getElementById(this.targetElementId);

        // move filter back to original place
        filterContent.innerHTML = oldChildNode.innerHTML;

        document.$emitter.unsubscribe(CLOSE_EVENT);
        window.PluginManager.getPluginInstances('Listing')[0].refreshRegistry();
    }

    /**
     * On clicking the trigger item the OffCanvas shall open and the current
     * filter content should be moved inside the OffCanvas.
     * @param {Event} event
     * @private
     */
    _onClickOffCanvasFilter(event) {
        event.preventDefault();

        const filterContent = document.getElementById(this.targetElementId);

        if (!filterContent) {
            throw Error('There was no DOM element with the data attribute "data-offcanvas-custom-content".');
        }

        filterContent.getElementsByClassName('panel-content')[0].classList.add('filter-panel');

        OffCanvas.open(
            filterContent.innerHTML,
            () => {},
            'bottom',
            true,
            OffCanvas.REMOVE_OFF_CANVAS_DELAY(),
            true,
            'offcanvas-filter'
        );

        // remove filter content from original place
        filterContent.innerHTML = '';

        window.PluginManager.getPluginInstances('Listing')[0].refreshRegistry();
        document.$emitter.unsubscribe(CLOSE_EVENT);
        document.$emitter.subscribe(CLOSE_EVENT, this._onCloseOffCanvas.bind(this));

        this.$emitter.publish('onClickOffCanvasFilter');
    }
}
