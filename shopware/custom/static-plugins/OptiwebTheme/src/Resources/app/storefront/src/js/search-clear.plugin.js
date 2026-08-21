import Plugin from 'src/plugin-system/plugin.class';

/**
 * Swaps the magnifier submit button for an "×" clear button as soon as the search
 * field holds text, and clears the field on Esc.
 *
 * The vendor SearchWidgetPlugin already wires the clear button's click handler
 * (empty the input, keep focus, close the suggest dropdown) but never toggles its
 * visibility — so on its own the button stays permanently hidden behind `.d-none`.
 */
export default class SearchClearPlugin extends Plugin {

    static options = {
        inputSelector: 'input[type=search]',
        clearButtonSelector: '.js-search-close-btn',
        hiddenClass: 'd-none',
    };

    init() {
        this._input = this.el.querySelector(this.options.inputSelector);
        this._clearButton = this.el.querySelector(this.options.clearButtonSelector);

        if (!this._input || !this._clearButton) {
            return;
        }

        this._registerEvents();
        this._toggleClearButton();
    }

    _registerEvents() {
        // `input` misses programmatic resets, `search` fires on the native clear affordance
        this._input.addEventListener('input', this._toggleClearButton.bind(this));
        this._input.addEventListener('search', this._toggleClearButton.bind(this));
        this._input.addEventListener('keydown', this._onKeyDown.bind(this));
        this._clearButton.addEventListener('click', this._toggleClearButton.bind(this));
    }

    /**
     * Esc clears the field when it holds text, otherwise it falls through so the
     * vendor plugin can close the suggest dropdown.
     */
    _onKeyDown(event) {
        if (event.key !== 'Escape' || this._input.value === '') {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        this._clearButton.click();
        this._toggleClearButton();
    }

    _toggleClearButton() {
        const isFilled = this._input.value !== '';

        this._clearButton.classList.toggle(this.options.hiddenClass, !isFilled);

        this.$emitter.publish('toggleClearButton', { isFilled });
    }
}
