import Plugin from 'src/plugin-system/plugin.class';
import DomAccess from 'src/helper/dom-access.helper';
import CookieStorageHelper from 'src/helper/storage/cookie-storage.helper';

export default class CategoryViewPlugin extends Plugin {

    static options = {

        /**
         * Grid button selector
         */
        viewGridButtonSelector: '.view-grid',

        /**
         * List button selector
         */
        viewListButtonSelector: '.view-list',

        /**
         * Category items wrapper selector
         */
        categoryViewSelector: '.category-view',

    };

    init() {
        this._viewGridButton = this.el.querySelector(this.options.viewGridButtonSelector);
        this._viewListButton = this.el.querySelector(this.options.viewListButtonSelector);
        this._categoryView = DomAccess.querySelector(document, this.options.categoryViewSelector);
        this._viewCookie = CookieStorageHelper.getItem('sw-category-view');

        if (!this._viewCookie) {
            this._viewCookie = this._viewListButton && this._viewListButton.classList.contains('active') ? 'list' : 'grid';
        }

        this._categoryView.dataset.view = 'view-' + this._viewCookie;

        this._registerEvents();
    }

    _registerEvents() {
        const that = this;
        this._viewListButton.addEventListener('click', function (event) {
            that._setView(event);
        });
        this._viewGridButton.addEventListener('click', function (event) {
            that._setView(event);
        });
    }

    _setView(event) {
        const buttonView = event.target.dataset.view;
        this._categoryView.dataset.view = 'view-' + buttonView;
        CookieStorageHelper.setItem('sw-category-view', buttonView, 7);
    }
}