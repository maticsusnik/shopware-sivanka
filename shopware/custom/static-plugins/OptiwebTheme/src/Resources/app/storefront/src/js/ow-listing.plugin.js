import ListingPlugin from 'src/plugin/listing/listing.plugin';
import DomAccess from 'src/helper/dom-access.helper';

export default class OwListingPlugin extends ListingPlugin {

    init() {
        super.init();
        this._checkFiltersOnLoad();
    }

    _checkFiltersOnLoad() {
        const filtersContainerEl = DomAccess.querySelector(document, this.options.activeFilterContainerSelector, false);
        const filtersContainerWrapperEl = DomAccess.querySelector(document, ".filter-panel-active-container-wrapper", false);

        filtersContainerWrapperEl.style.display = "none";

        if (filtersContainerEl && filtersContainerEl.childNodes.length > 0) {
            filtersContainerWrapperEl.style.display = "flex";
        }

    }

    _setFilterState(filterItem) {
        super._setFilterState(filterItem);
        this._checkFiltersOnLoad();
    }

    _buildLabels() {
        super._buildLabels();
        this._checkFiltersOnLoad();
    }

}
