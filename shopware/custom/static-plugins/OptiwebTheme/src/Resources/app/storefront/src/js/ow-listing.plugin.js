import ListingPlugin from 'src/plugin/listing/listing.plugin';

export default class OwListingPlugin extends ListingPlugin {

    init() {
        super.init();
        this._checkFiltersOnLoad();
    }

    _checkFiltersOnLoad() {
        const filtersContainerEl = document.querySelector(this.options.activeFilterContainerSelector);
        const filtersContainerWrapperEl = document.querySelector('.filter-panel-active-container-wrapper');

        // search/other listings without the theme's listing template have no wrapper
        if (!filtersContainerWrapperEl) {
            return;
        }

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
