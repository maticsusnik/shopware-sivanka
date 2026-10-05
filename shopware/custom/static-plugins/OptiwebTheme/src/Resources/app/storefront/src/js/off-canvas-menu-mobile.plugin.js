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

    // Mirrors core OffcanvasMenuPlugin._registerEvents() (6.7.x) plus the footer/close handling.
    _registerEvents() {
        // A fresh bind() every call never matched in removeEventListener, so the trigger
        // piled up one more handler per offcanvas open. Cache it like core does.
        if (!this._boundGetLinkEventHandler) {
            this._boundGetLinkEventHandler = this._getLinkEventHandler.bind(this);
        }

        if (!this._boundClose) {
            this._boundClose = this._close.bind(this);
        }

        this.el.removeEventListener(this.options.triggerEvent, this._boundGetLinkEventHandler);
        this.el.addEventListener(this.options.triggerEvent, this._boundGetLinkEventHandler);

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
                closeTriggers.forEach(trigger => {
                    trigger.removeEventListener('click', this._boundClose);
                    trigger.addEventListener('click', this._boundClose);
                });

                // only the new offcanvas content needs plugins, not the whole page again
                window.PluginManager.initializePluginsInParentElement(offcanvas);
            });
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