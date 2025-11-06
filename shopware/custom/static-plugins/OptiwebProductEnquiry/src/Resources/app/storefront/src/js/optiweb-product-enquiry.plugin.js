import FormCmsHandler from 'src/plugin/forms/form-cms-handler.plugin';

export default class OptiwebProductEnquiryPlugin extends FormCmsHandler {
    init() {
        // Find the form element within the wrapper div
        const form = this.el.querySelector('form');
        if (form) {
            // Temporarily store the original el
            this._originalEl = this.el;
            // Set el to the form element for FormCmsHandler to work properly
            this.el = form;
        }
        super.init();
        
        // Ensure form validation is properly initialized
        this._initValidation();
    }
    
    /**
     * Initialize form validation
     * @private
     */
    _initValidation() {
        const form = this.el;
        if (!form) {
            return;
        }
        
        // Add HTML5 validation attributes if not already present
        const requiredFields = form.querySelectorAll('[required]');
        requiredFields.forEach((field) => {
            if (field.type === 'email' && !field.hasAttribute('pattern')) {
                // Email validation is handled by type="email"
                field.setAttribute('type', 'email');
            }
            
            if (field.type === 'number' && field.name === 'productQty') {
                if (!field.hasAttribute('min')) {
                    field.setAttribute('min', '1');
                }
                if (!field.hasAttribute('step')) {
                    field.setAttribute('step', '1');
                }
            }
        });
        
        // Add custom validation for quantity field
        const qtyField = form.querySelector('input[name="productQty"]');
        if (qtyField) {
            qtyField.addEventListener('input', () => {
                this._validateQuantityField(qtyField);
            });
            
            qtyField.addEventListener('invalid', (e) => {
                this._handleQuantityInvalid(e);
            });
        }
    }
    
    /**
     * Validate quantity field
     * @param {HTMLElement} field
     * @private
     */
    _validateQuantityField(field) {
        const value = parseFloat(field.value);
        
        if (isNaN(value) || value < 1) {
            field.setCustomValidity(this._getValidationMessage('quantity', 'invalid'));
        } else {
            field.setCustomValidity('');
        }
    }
    
    /**
     * Handle quantity field invalid event
     * @param {Event} e
     * @private
     */
    _handleQuantityInvalid(e) {
        const field = e.target;
        const value = parseFloat(field.value);
        
        if (field.value === '' || field.value === null) {
            field.setCustomValidity(this._getValidationMessage('quantity', 'required'));
        } else if (isNaN(value) || value < 1) {
            field.setCustomValidity(this._getValidationMessage('quantity', 'min'));
        }
    }
    
    /**
     * Get validation message
     * @param {string} field
     * @param {string} type
     * @returns {string}
     * @private
     */
    _getValidationMessage(field, type) {
        const messages = {
            quantity: {
                required: 'This field is required.',
                invalid: 'Please enter a valid quantity.',
                min: 'Quantity must be at least 1.'
            }
        };
        
        return messages[field] && messages[field][type] ? messages[field][type] : 'This field is invalid.';
    }

    _submitForm() {
        const products = this._getProductEnquiryItems();

        // Google Analytics / GTM tracking
        if (window.dataLayer) {
            window.dataLayer.push({
                'event': 'productEnquiry',
                'ecommerce': {
                    'productEnquiry': {
                        'products': products
                    }
                }
            });
        }

        super._submitForm();
    }

    /**
     * Get product enquiry items for tracking
     * @returns {Array}
     * @private
     */
    _getProductEnquiryItems() {
        return [{
            'id': this._getValueFromInput('product_id'),
            'name': this._getValueFromInput('product_name'),
            'product_number': this._getValueFromInput('product_number'),
            'variant': this._getValueFromInput('product_option'),
        }];
    }

    /**
     * Get value from input field
     * @param {string} name
     * @returns {string}
     * @private
     */
    _getValueFromInput(name) {
        const input = this.el.querySelector(`input[name="${name}"]`);
        return input ? input.value : '';
    }

    _createResponse(changeContent, content) {
        super._createResponse(changeContent, content);

        // Show close button if available
        const closeButton = document.querySelector('.enquiry-form-close');
        if (closeButton) {
            closeButton.classList.remove('d-none');
        }
    }
}
