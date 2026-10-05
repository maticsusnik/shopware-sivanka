import Plugin from 'src/plugin-system/plugin.class';
import PseudoModalUtil from 'src/utility/modal-extension/pseudo-modal.util';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';

/**
 * Bulk product enquiry from the wishlist.
 *
 * Collects the checked wishlist cards and opens the shared enquiry modal with
 * every selected product, so one submission covers the whole selection.
 *
 * The wishlist has two very different renderings — a logged-in customer's list
 * comes from the server, a guest's is fetched over AJAX after page load — so the
 * cards are never assumed to exist at init time. The bar observes the listing
 * and re-reads the checkboxes whenever it changes.
 */
export default class OptiwebWishlistEnquiryPlugin extends Plugin {

    static options = {
        modalUrl: '',
        checkboxSelector: '.js-wishlist-enquiry-checkbox',
        submitSelector: '.js-wishlist-enquiry-submit',
        toggleAllSelector: '.js-wishlist-enquiry-toggle-all',
        summarySelector: '.js-wishlist-enquiry-summary',
        listingSelector: '.cms-listing-row, .wishlist-page, .cms-page',
        // Mirrors MAX_PRODUCTS in ProductEnquiryCmsElementResolver.
        maxProducts: 100,
    };

    init() {
        if (!this.options.modalUrl) {
            return;
        }

        this._submitButton = this.el.querySelector(this.options.submitSelector);
        this._toggleAllButton = this.el.querySelector(this.options.toggleAllSelector);
        this._summary = this.el.querySelector(this.options.summarySelector);

        this._registerEvents();
        this._observeListing();
        this._refresh();
    }

    _registerEvents() {
        this._submitButton?.addEventListener('click', () => this._openModal());
        this._toggleAllButton?.addEventListener('click', () => this._toggleAll());

        // Delegated: the checkboxes live outside this element and are replaced
        // wholesale every time the guest wishlist re-renders.
        document.addEventListener('change', (event) => {
            if (event.target?.matches?.(this.options.checkboxSelector)) {
                this._refresh();
            }
        });
    }

    /**
     * Re-read the selection whenever the listing changes — a guest list arriving,
     * or any card being removed from the wishlist.
     */
    _observeListing() {
        const target = document.querySelector(this.options.listingSelector) ?? document.body;

        this._observer = new MutationObserver(() => {
            window.clearTimeout(this._refreshTimeout);
            this._refreshTimeout = window.setTimeout(() => this._refresh(), 100);
        });

        this._observer.observe(target, { childList: true, subtree: true });
    }

    /** @returns {HTMLInputElement[]} */
    _getCheckboxes() {
        return Array.from(document.querySelectorAll(this.options.checkboxSelector));
    }

    /** Distinct product ids, so counts and the submitted set can never disagree. */
    _getIds(onlyChecked) {
        return this._getCheckboxes()
            .filter((checkbox) => !onlyChecked || checkbox.checked)
            .map((checkbox) => checkbox.value)
            .filter((value, index, all) => value && all.indexOf(value) === index);
    }

    _getSelectedIds() {
        return this._getIds(true).slice(0, this.options.maxProducts);
    }

    _refresh() {
        const total = this._getIds(false).length;
        const selected = this._getSelectedIds().length;

        // Nothing on the wishlist yet (or an empty wishlist) — stay out of the way.
        this.el.classList.toggle('is-empty', total === 0);

        if (this._submitButton) {
            this._submitButton.disabled = selected === 0;
        }

        if (this._toggleAllButton) {
            const allSelected = total > 0 && selected === total;
            this._toggleAllButton.textContent = allSelected
                ? this._snippet('deselectAll')
                : this._snippet('selectAll');
            this._toggleAllButton.dataset.action = allSelected ? 'deselect' : 'select';
        }

        if (this._summary) {
            this._summary.textContent = this._summaryText(selected);
        }
    }

    _summaryText(selected) {
        if (selected === 0) {
            return this._snippet('summaryNone');
        }

        if (selected === 1) {
            return this._snippet('summaryOne');
        }

        return this._snippet('summaryMany').replace('%count%', String(selected));
    }

    _toggleAll() {
        const select = this._toggleAllButton?.dataset.action !== 'deselect';

        this._getCheckboxes().forEach((checkbox) => {
            checkbox.checked = select;
        });

        this._refresh();
    }

    _openModal() {
        const ids = this._getSelectedIds();
        if (ids.length === 0) {
            return;
        }

        const url = new URL(this.options.modalUrl, window.location.origin);
        ids.forEach((id) => url.searchParams.append('productIds[]', id));

        ElementLoadingIndicatorUtil.create(this.el);

        fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Enquiry modal request failed with HTTP ${response.status}`);
                }

                return response.text();
            })
            .then((content) => {
                const modal = new PseudoModalUtil(content);
                modal.open(() => window.PluginManager.initializePlugins());
            })
            .catch((error) => {
                console.error('[OptiwebWishlistEnquiry]', error);
                this._showError();
            })
            .finally(() => ElementLoadingIndicatorUtil.remove(this.el));
    }

    _showError() {
        if (!this._summary) {
            return;
        }

        this._summary.textContent = this._snippet('unexpectedError');
        this._summary.classList.add('has-error');
    }

    /**
     * Snippets are rendered into data attributes on the bar, so the plugin never
     * has to hardcode a language.
     */
    _snippet(key) {
        return this.el.dataset[`snippet${key.charAt(0).toUpperCase()}${key.slice(1)}`] ?? '';
    }
}
