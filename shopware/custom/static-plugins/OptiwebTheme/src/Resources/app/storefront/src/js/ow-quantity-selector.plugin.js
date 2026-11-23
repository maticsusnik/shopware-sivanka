import QuantitySelectorPlugin from 'src/plugin/quantity-selector/quantity-selector.plugin';

/**
 * Disables [+] once the quantity reaches the input's max (for a clearance
 * product that is the available stock) and [-] once it reaches the min, so
 * the customer cannot click past what is actually buyable.
 *
 * Covers every quantity selector: product page, cart, offcanvas cart and
 * checkout confirm.
 */
export default class OwQuantitySelectorPlugin extends QuantitySelectorPlugin {

    init() {
        super.init();

        this._input.addEventListener('input', this._updateButtonState.bind(this));
        this._input.addEventListener('change', this._updateButtonState.bind(this));
        this._updateButtonState();
    }

    _stepUp() {
        super._stepUp();
        this._updateButtonState();
    }

    _stepDown() {
        super._stepDown();
        this._updateButtonState();
    }

    _applyPurchaseLimit(limits) {
        super._applyPurchaseLimit(limits);
        this._updateButtonState();
    }

    _updateButtonState() {
        // A locked selector (digital product, out of stock) stays locked.
        if (this._input.disabled) {
            this._btnPlus.disabled = true;
            this._btnMinus.disabled = true;
            return;
        }

        const value = parseFloat(this._input.value);
        const max = parseFloat(this._input.getAttribute('max'));
        const min = parseFloat(this._input.getAttribute('min'));

        if (Number.isNaN(value)) {
            return;
        }

        this._btnPlus.disabled = !Number.isNaN(max) && value >= max;
        this._btnMinus.disabled = !Number.isNaN(min) && value <= min;
    }
}
