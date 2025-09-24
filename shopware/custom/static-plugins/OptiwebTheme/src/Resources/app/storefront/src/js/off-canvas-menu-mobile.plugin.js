import OffcanvasMenuPlugin from 'src/plugin/main-menu/offcanvas-menu.plugin';
import OffCanvas from 'src/plugin/offcanvas/offcanvas.plugin';


export default class OffcanversMenuMobilePlugin extends OffcanvasMenuPlugin {

    static options = {
        ...OffcanvasMenuPlugin.options,
        offcanvasCloseSelector: '.js-offcanvas-close',
        offcanvasFooterSelector: '.offcanvas-footer',
        offcavasBackClass: 'is-back-link',
        offcavasCurrentCategorySelector: '.is-current-category',
    };

    _registerEvents() {
        this.el.removeEventListener(this.options.triggerEvent, this._getLinkEventHandler.bind(this));
        this.el.addEventListener(this.options.triggerEvent, this._getLinkEventHandler.bind(this));

        if (OffCanvas.exists()) {
            const offCanvasElements = OffCanvas.getOffCanvas();

            offCanvasElements.forEach(offcanvas => {
                const links = offcanvas.querySelectorAll(this.options.linkSelector);
                links.forEach(link => {
                    OffcanvasMenuPlugin._resetLoader(link);
                    link.addEventListener('click', (event) => {
                        this._getLinkEventHandler(event, link);
                    });
                });

                const currentCategory = offcanvas.querySelector(this.options.offcavasCurrentCategorySelector);

                if (currentCategory) {
                    this._setFooterVisibility(currentCategory);
                }


                const closeTriggers = document.querySelectorAll(this.options.offcanvasCloseSelector);
                closeTriggers.forEach(trigger => trigger.addEventListener('click', this._close.bind(this)));
            });
            // initialize the plugins again, after Off-Canvas init, otherwise you will miss the JS event listener
            window.PluginManager.initializePlugins();
        }
        // re-open the menu if the url parameter is set
        this._openMenuViaUrlParameter();
    }

    _close(element) {
        OffCanvas.close(element);
    }

    _getLinkEventHandler(event, link) {
        this._setFooterVisibility(link);

        super._getLinkEventHandler(event, link);
    }

    _setFooterVisibility(link) {
        if (link?.classList.contains(this.options.offcavasBackClass)) {
            this._currentLevel = Math.max(1, this._currentLevel - 1);
        } else if (link?.getAttribute('data-category-level')) {
            this._currentLevel = parseInt(link?.getAttribute('data-category-level'));
        } else {
            const url = link?.getAttribute('data-href') || link?.getAttribute('href');
            this._currentLevel = url ? parseInt(new URLSearchParams(url.split('?')[1]).get('itemLevel') ?? 1) : 1;
        }

        document.querySelectorAll(this.options.offcanvasFooterSelector).forEach((footer) => {
            footer.classList.toggle('d-none', this._currentLevel > 1);
        });
    }
}