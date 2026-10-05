import FilterPropertySelectPlugin from 'src/plugin/listing/filter-property-select.plugin';
import deepmerge from 'deepmerge';
import DomAccess from 'src/helper/dom-access.helper';
import Iterator from 'src/helper/iterator.helper';

export default class FilterToggleCheckboxesPlugin extends FilterPropertySelectPlugin {

    static options = deepmerge(FilterPropertySelectPlugin.options, {});

    init() {
        this.options = deepmerge(FilterPropertySelectPlugin.options, JSON.parse(this.el.dataset.filterPropertySelectOptions));
        this.showAllButton = this.el.querySelector('.filter-show-all');
        this.listItems = this.el.querySelectorAll('.filter-multi-select-list-item');
        this.collapseElement = this.el.querySelector('.collapse');
        super.init();
    }

    refreshDisabledState(filter) {
        // Prevent disabling if propertyName is not set correctly
        if (this.options.propertyName === '') {
            return;
        }

        const activeItems = [];
        const properties = filter[this.options.name];
        const entities = properties.entities;

        if (!entities) {
            this.disableFilter();
            return;
        }

        const property = entities.find(entity => entity.translated.name === this.options.propertyName);
        if (property) {
            activeItems.push(
                ...property.options.filter(option => option.selected)
            )
            activeItems.push(...property.options);
            this._expandFilter();
        } else {
            this.disableFilter();
            this._disableInactiveFilterOptions(activeItems.map(entity => entity.id));
            this._collapseFilter();
            return;
        }

        const actualValues = this.getValues();

        if (activeItems.length < 1 && actualValues.properties.length === 0) {
            this.disableFilter();
            return;
        } else {
            this.enableFilter();
        }

        if (actualValues.properties.length > 0) {
            return;
        }

        this._disableInactiveFilterOptions(activeItems.map(entity => entity.id));
        this._manageExpandButton();

    }

    _expandFilter() {
        this.collapseElement.classList.add('show');
        this.el.style.display = "grid";
    }

    _collapseFilter() {
        this.collapseElement.classList.remove('show');
        this.el.style.setProperty("display", "none", "important");
    }

    _manageExpandButton() {
        let number = 0;
        const checkboxes = DomAccess.querySelectorAll(this.el, this.options.checkboxSelector);
        Iterator.iterate(checkboxes, (checkbox) => {
            if (!checkbox.disabled) {
                number++;
            }
        });

        if (number > 5) {
            this._showExpandButton();
        } else {
            this._hideExpandButton();
        }
    }

    _showExpandButton() {
        let loopNumber = 0;
        this.showAllButton.style.display = "block";

        this.listItems.forEach(element => {
            if (!element.classList.contains('disabled')) {
                element.classList.add('active');
            } else {
                element.classList.remove('active');
            }

            if (loopNumber >= 5) {
                if (!element.classList.contains('disabled')) {
                    element.classList.add('hidden');
                    element.classList.add('removable');
                } else {
                    element.classList.remove('removable');
                }
            }

            loopNumber++;
        });


        let listItemsActive = this.el.querySelectorAll('.filter-multi-select-list-item.active');

        if (listItemsActive.length > 0) {
            for (let i = 0; i < 5; i++) {
                if (listItemsActive[i]) {
                    listItemsActive[i].classList.remove('hidden');
                    listItemsActive[i].classList.remove('removable');
                }
            }
        }
    }

    _hideExpandButton() {
        this.showAllButton.style.display = "none";

        this.listItems.forEach(element => {
            if (element.classList.contains('disabled')) {
                element.classList.remove('active');
            }

            if (element.classList.contains('active')) {
                element.classList.remove('removable');
                element.classList.remove('hidden');
            }
        })
    }

}
