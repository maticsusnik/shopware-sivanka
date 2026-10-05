import OffcanvasMenuPlugin from 'src/plugin/main-menu/offcanvas-menu.plugin';
import OffCanvas from 'src/plugin/offcanvas/offcanvas.plugin';
import ViewportDetection from 'src/helper/viewport-detection.helper';
import NativeEventEmitter from 'src/helper/emitter.helper';


export default class OffcanversMenuDesktopPlugin extends OffcanvasMenuPlugin {

    static options = {
        ...OffcanvasMenuPlugin.options,
        position: 'central',
        triggerEvent: 'mouseenter',
        triggerEventSubmenu: 'click',
        initialContentSelector: '.js-navigation-offcanvas-initial-content-desktop',
        additionalOffcanvasClass: 'navigation-offcanvas-desktop',
        navigationDesktopPlaceholderClass: '.navigation-offcanvas-desktop-placeholder',
        backdropClass: '.offcanvas-backdrop',
        submenuSelector: '.navigation-offcanvas-container-level-',
        activeClass: 'is-active',
        linkNoSumenuSelector: '.navigation-offcanvas-link',
        preloadOnViewPorts: ['LG', 'XL', 'XXL'],
        closeDropdownForSelectors: '.offcanvas-backdrop, .main-navigation-menu, .header-main'
    };

    init() {
        super.init();

        this._mainCategory = this.el.getAttribute('data-main-category');
        this._currentLevel = 1;
        this._maxLevel = 4;
        this._preloadedCategories = [];
        this._anyRequestWasAborted = false;
        this._abortController = null;
        this._isOpened = false;

        this.$emitter = new NativeEventEmitter();
        this.$emitter.subscribe('onCloseOffcanvas', (eventData) => {
            this._isOpened = false;
        });


        if (this.options.preloadOnViewPorts.includes(ViewportDetection.getCurrentViewport())) {
            this._preLoadMenuData(1);
        }
    }

    async _preLoadMenuData(level) {

        if (this._abortController) {
            this._abortController.abort();
        }
        this._abortController = new AbortController();
        const signal = this._abortController.signal;

        let requestUrls = [];
        if (level === 1) {
            requestUrls[this._mainCategory] = `${this.options.navigationUrl}?navigationId=${this._mainCategory}`;
        } else {
            const container = this._getOffcanvasMenu(level - 1);

            const currentLevelCategories = container.querySelectorAll(`[data-preload-category-id][data-category-level="${level}"]`);

            for (let category of currentLevelCategories) {
                let categoryId = category.getAttribute("data-preload-category-id");
                if (!this._preloadedCategories.includes(categoryId)) {
                    requestUrls[categoryId] = `${this.options.navigationUrl}?navigationId=${categoryId}&itemLevel=${level}`;
                }
            }
        }

        const promises = Object.entries(requestUrls).map(([categoryId, url]) =>
            new Promise((resolve) => {
                this._fetchMenu(
                    url,
                    categoryId,
                    (htmlResponse) => {
                        if (!signal.aborted) {
                            console.log(`Preloaded data for: ${url}`);
                            this._preloadedCategories.push(categoryId);
                        }
                        resolve();
                    },
                    signal
                );
            })
        );

        await Promise.all(promises);
    }

    _openMenu(event) {
        if (this._isOpened) {
            return;
        }
        this._isOpened = true;

        super._openMenu(event);

        this._preLoadMenuData(this._currentLevel + 1);

        this._anyRequestWasAborted = false;

        const dropdown = document.querySelector('.' + this.options.additionalOffcanvasClass),
            navigationDesktopPlaceholder = document.querySelector(this.options.navigationDesktopPlaceholderClass),
            backdrop = document.querySelector(this.options.backdropClass);

        document.body.style.overflow = '';
        document.body.style.paddingRight = '';


        const headerHeight = document.querySelector('.header-main')?.offsetHeight || 0;
        const navigationHeight = document.querySelector('.nav-main')?.offsetHeight || 0;
        const totalHeight = headerHeight + navigationHeight;

        backdrop.style.position = 'absolute';
        backdrop.style.width = '100%';
        // backdrop.style.top = '140px';
        backdrop.style.top = totalHeight + 'px';
        // backdrop.style.height = (document.documentElement.scrollHeight - 140) + 'px';
        backdrop.style.height = (document.documentElement.scrollHeight - 185) + 'px';

        navigationDesktopPlaceholder.appendChild(dropdown);

    }

    _updateContent(content) {

        this._content = content;

        if (OffCanvas.exists()) {
            const container = this._getOffcanvasMenu();
            container.innerHTML = content;
            const currentCategory = container.querySelector(this.options.currentCategorySelector);
            window.focusHandler.setFocus(currentCategory, {focusVisible: true});

            this._registerEvents();
        }

        this._hideSubmenus(this._currentLevel + 1);

        this._preLoadMenuData(this._currentLevel + 1);

        if (this._anyRequestWasAborted) {
            this._abortController = null;
            this._preLoadMenuData(this._currentLevel);
            this._anyRequestWasAborted = false;
        }

        let backdrop = document.querySelector(this.options.backdropClass);
         if (backdrop) {
             backdrop.classList.remove('show');
         }

        this.$emitter.publish('updateContent');
    }

    _getLinkEventHandler(event, link) {
        if (this._abortController) {
            this._abortController.abort();
        }

        if (this._loading) {
            console.log("Loading already in progress. Aborting request.");
            return;
        }

        if (!link) {
            this._loading = true
            const initialContentElement = document.querySelector(this.options.initialContentSelector);
            const url = `${this.options.navigationUrl}?navigationId=${this._mainCategory}`;

            return this._fetchMenu(url, this._mainCategory, (htmlResponse) => {
                this._loading = false;
                const navigationContainer = initialContentElement.querySelector(this.options.menuSelector);
                navigationContainer.innerHTML = htmlResponse;

                this._content = initialContentElement.innerHTML;

                return this._openMenu(event);
            });
        }

        OffcanvasMenuPlugin._stopEvent(event);
        if (link.classList.contains(this.options.linkLoadingClass)) {
            return;
        }

        OffcanvasMenuPlugin._setLoader(link);

        const url = link.getAttribute('data-href') || link.getAttribute('href');

        if (!url) {
            return;
        }

        this._setCurrentLevelFromUrl(url);
        this._setActive(link);

        this.$emitter.publish('getLinkEventHandler');

        this._fetchMenu(url, this._navigationId, this._updateContent.bind(this));
    }

    _fetchMenu(link, navigationId, cb, signal) {
        if (!link) {
            return false;
        }

        if (this._cache[link]) {
            if (typeof cb === 'function') {
                return cb(this._cache[link]);
            }
        }

        this.$emitter.publish('beforeFetchMenu');

        fetch(link, {
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            signal: signal,
        })
            .then((res) => res.text())
            .then((content) => {
                this._cache[link] = content;
                if (typeof cb === 'function') {
                    cb(content);
                }
            })
            .catch((error) => {
                if (signal && signal.aborted) {
                    console.log(`Request for ${link} was aborted.`);
                    this._anyRequestWasAborted = true;
                    if (navigationId) {
                        this._preloadedCategories.splice(navigationId, 1);
                    }

                } else {
                    console.error(`Error fetching menu: ${link}`, error);
                }
            });
    }

    _getOffcanvasMenu(level = this._currentLevel) {
        const offcanvas = OffcanvasMenuPlugin._getOffcanvas();

        if (level && level > 1) {
            return offcanvas.querySelector(this.options.submenuSelector + level);
        }

        return offcanvas.querySelector(this.options.menuSelector);
    }

    _hideSubmenus(level) {
        const offcanvas = OffcanvasMenuPlugin._getOffcanvas();

        if (!offcanvas) {
            return;
        }

        for (level; level <= this._maxLevel; level++) {
            let element = offcanvas.querySelector(this.options.submenuSelector + level);

            if (element) {
                element.innerHTML = '';
            }
        }
    }

    _setActive(link) {
        // remove all active links on the same level and above
        this._removeActiveForLevel(this._currentLevel - 1);

        link.classList.add(this.options.activeClass);
    }

    _removeActiveForLevel(level) {
        const offcanvas = document.querySelector('.' + this.options.additionalOffcanvasClass);
        for (level; level <= this._maxLevel; level++) {
            let levelContainer = offcanvas.querySelector(this.options.submenuSelector + level);
            levelContainer.querySelectorAll('.' + this.options.activeClass).forEach(element => {
                element.classList.remove(this.options.activeClass)
            });
        }
    }

    _setCurrentLevelFromUrl(url) {
        const params = new URLSearchParams(url.split('?')[1]);
        this._currentLevel = parseInt(params.get('itemLevel') ?? 1);
        this._navigationId = params.get('navigationId');
    }

    _registerEvents() {
        this.el.removeEventListener(this.options.triggerEvent, this._getLinkEventHandler.bind(this));
        this.el.addEventListener(this.options.triggerEvent, this._getLinkEventHandler.bind(this));

        if (OffCanvas.exists()) {
            const offCanvasElements = OffCanvas.getOffCanvas();

            offCanvasElements.forEach(offcanvas => {
                const links = offcanvas.querySelectorAll(this.options.linkSelector);
                links.forEach(link => {
                    OffcanvasMenuPlugin._resetLoader(link);
                    link.addEventListener(this.options.triggerEventSubmenu, (event) => {
                        this._setCurrentLevelFromUrl(link.getAttribute("data-href"));
                        this._removeActiveForLevel(this._currentLevel - 1);
                        this._hideSubmenus(this._currentLevel);
                        this._getLinkEventHandler(event, link);
                    });
                });

                const linksNoSubmenu = offcanvas.querySelectorAll(this.options.linkNoSumenuSelector);
                linksNoSubmenu.forEach(link => {
                    link.addEventListener(this.options.triggerEventSubmenu, (event) => {
                        this._currentLevel = parseInt(link.getAttribute("data-category-level"));
                        if (link.classList.contains('is-current-category')) {
                            this._currentLevel++;
                        }
                        this._removeActiveForLevel(this._currentLevel - 1);
                        this._hideSubmenus(this._currentLevel);
                    });
                });
            });
            const closeTriggers = document.querySelectorAll(this.options.closeDropdownForSelectors);
            closeTriggers.forEach(closeTrigger => {
                closeTrigger.removeEventListener(this.options.triggerEvent, this._closeDropdown.bind(this));
                closeTrigger.addEventListener(this.options.triggerEvent, this._closeDropdown.bind(this));
            });

            // initialize the plugins again, after Off-Canvas init, otherwise you will miss the JS event listener
            window.PluginManager.initializePlugins();
        }


        // re-open the menu if the url parameter is set
        this._openMenuViaUrlParameter();
    }

    _closeDropdown(event) {
        if (this._isOpened) {
            OffCanvas.close();
        }
    }
}