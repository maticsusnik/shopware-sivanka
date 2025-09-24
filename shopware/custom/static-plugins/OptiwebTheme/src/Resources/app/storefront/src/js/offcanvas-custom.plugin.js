import OffCanvas from 'src/plugin/offcanvas/offcanvas.plugin';
import OffCanvasFilter from 'src/plugin/offcanvas-filter/offcanvas-filter.plugin';

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

        document.$emitter.unsubscribe('onCloseOffcanvas', this._onCloseOffCanvas.bind(this));
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

        filterContent.getElementsByClassName('panel-content')[0].classList.add('filter-panel');

        if (!filterContent) {
            throw Error('There was no DOM element with the data attribute "data-offcanvas-custom-content".');
        }

        console.log(filterContent);

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
        document.$emitter.subscribe('onCloseOffcanvas', this._onCloseOffCanvas.bind(this));

        this.$emitter.publish('onClickOffCanvasFilter');
    }
}
