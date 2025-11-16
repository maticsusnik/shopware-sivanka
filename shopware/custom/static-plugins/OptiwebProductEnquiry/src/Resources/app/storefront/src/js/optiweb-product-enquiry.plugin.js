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
        
        // Initialize field validation listeners
        this._initFieldValidation();
    }
    
    /**
     * Initialize field validation listeners
     * @private
     */
    _initFieldValidation() {
        const form = this.el;
        if (!form) {
            return;
        }
        
        // Listen to input events to validate fields in real-time
        const fields = form.querySelectorAll('input, textarea, select');
        fields.forEach((field) => {
            // Validate on input (user typing)
            field.addEventListener('input', () => {
                this._validateField(field);
            });
            
            // Validate on blur (field loses focus)
            field.addEventListener('blur', () => {
                this._validateField(field);
            });
        });
    }
    
    /**
     * Validate a single field
     * @param {HTMLElement} field
     * @private
     */
    _validateField(field) {
        // Clear any custom validity that might block submission
        field.setCustomValidity('');
        
        // Clear server error flag when user starts interacting with the field
        if (field.hasAttribute('data-server-error')) {
            field.removeAttribute('data-server-error');
        }
        
        // Check if field is valid
        if (field.checkValidity() && field.value && field.value.trim() !== '') {
            // Field is valid
            field.classList.remove('is-invalid');
            field.classList.add('is-valid');
            field.removeAttribute('aria-invalid');
            
            // Hide error message
            const errorElement = field.parentElement.querySelector('.invalid-feedback');
            if (errorElement) {
                errorElement.style.display = 'none';
            }
        } else if (field.hasAttribute('required') && (!field.value || field.value.trim() === '')) {
            // Required field is empty
            field.classList.remove('is-valid');
            field.classList.add('is-invalid');
            field.setAttribute('aria-invalid', 'true');
        } else if (!field.checkValidity()) {
            // Field is invalid
            field.classList.remove('is-valid');
            field.classList.add('is-invalid');
            field.setAttribute('aria-invalid', 'true');
        } else {
            // Field is optional and empty - neutral state
            field.classList.remove('is-valid', 'is-invalid');
            field.removeAttribute('aria-invalid');
        }
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

    _handleResponse(res) {
        const response = JSON.parse(res);
        this.$emitter.publish('onFormResponse', res);

        this.el.dispatchEvent(new CustomEvent('removeLoader'));

        if (response.length > 0) {
            let changeContent = true;
            let content = '';
            
            for (let i = 0; i < response.length; i += 1) {
                if (response[i].type === 'danger' || response[i].type === 'info') {
                    changeContent = false;
                    
                    // Handle field-specific errors from server
                    if (response[i].fieldErrors && typeof response[i].fieldErrors === 'object') {
                        this._handleFieldErrors(response[i].fieldErrors);
                    }
                }
                content += response[i].alert;
            }

            // Reset form after successful submission to clear form contents.
            if (changeContent) {
                this.el.reset();
                this._clearAllFieldErrors();
            }

            this._createResponse(changeContent, content);
        } else {
            window.location.reload();
        }
    }
    
    /**
     * Handle field-specific errors from server response
     * @param {Object} fieldErrors
     * @private
     */
    _handleFieldErrors(fieldErrors) {
        // Mark each field with error from server
        Object.keys(fieldErrors).forEach((fieldName) => {
            const field = this.el.querySelector(`[name="${fieldName}"]`);
            if (field) {
                // Remove valid state
                field.classList.remove('is-valid');
                // Add invalid state
                field.classList.add('is-invalid');
                field.setAttribute('aria-invalid', 'true');
                // Mark as server error - but allow re-validation when user types
                field.setAttribute('data-server-error', 'true');
                // Clear any custom validity to allow form submission after fixing
                field.setCustomValidity('');
                
                // Find or create error message element
                let errorElement = field.parentElement.querySelector('.invalid-feedback');
                if (!errorElement) {
                    errorElement = document.createElement('div');
                    errorElement.className = 'invalid-feedback';
                    field.parentElement.appendChild(errorElement);
                }
                errorElement.textContent = fieldErrors[fieldName];
                errorElement.style.display = 'block';
                
                // Hide any valid feedback
                const validFeedback = field.parentElement.querySelector('.valid-feedback');
                if (validFeedback) {
                    validFeedback.style.display = 'none';
                }
            }
        });
        
        // Scroll to first error field
        const firstErrorField = this.el.querySelector('.is-invalid');
        if (firstErrorField) {
            setTimeout(() => {
                firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstErrorField.focus();
            }, 100);
        }
    }
    
    /**
     * Clear all field errors
     * @private
     */
    _clearAllFieldErrors() {
        const invalidFields = this.el.querySelectorAll('.is-invalid');
        invalidFields.forEach((field) => {
            field.classList.remove('is-invalid');
            field.removeAttribute('aria-invalid');
            field.removeAttribute('data-server-error');
            // Clear any custom validity
            field.setCustomValidity('');
            
            const errorElement = field.parentElement.querySelector('.invalid-feedback');
            if (errorElement) {
                errorElement.style.display = 'none';
            }
        });
        
        // Also clear all valid states
        const validFields = this.el.querySelectorAll('.is-valid');
        validFields.forEach((field) => {
            field.classList.remove('is-valid');
        });
        
        // Clear custom validity from all fields to ensure form can be submitted
        const allFields = this.el.querySelectorAll('input, textarea, select');
        allFields.forEach((field) => {
            field.setCustomValidity('');
        });
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
