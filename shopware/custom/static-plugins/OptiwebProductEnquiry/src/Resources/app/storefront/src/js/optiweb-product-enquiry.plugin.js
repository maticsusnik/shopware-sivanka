import Plugin from 'src/plugin-system/plugin.class';

export default class OptiwebProductEnquiryPlugin extends Plugin {

    init() {
        this._wrapper = this.el;
        this._form = this.el.querySelector('form') ?? this.el.closest('form');
        if (!this._form) return;

        this._form.addEventListener('submit', (e) => this._onSubmit(e));
        this._initFieldValidation();
    }

    _initFieldValidation() {
        this._form.querySelectorAll('input, textarea, select').forEach((field) => {
            field.addEventListener('input', () => this._validateField(field));
            field.addEventListener('blur', () => this._validateField(field));
        });
    }

    _validateField(field) {
        field.setCustomValidity('');
        if (field.dataset.serverError) {
            delete field.dataset.serverError;
        }

        const feedback = field.parentElement?.querySelector('.invalid-feedback');

        if (!field.value && field.type !== 'checkbox' && !field.required) {
            field.classList.remove('is-valid', 'is-invalid');
            return;
        }

        if (field.checkValidity()) {
            field.classList.add('is-valid');
            field.classList.remove('is-invalid');
            field.removeAttribute('aria-invalid');
            if (feedback) feedback.style.display = 'none';
        } else {
            field.classList.add('is-invalid');
            field.classList.remove('is-valid');
            field.setAttribute('aria-invalid', 'true');
        }
    }

    _onSubmit(e) {
        e.preventDefault();
        if (!this._form.checkValidity()) {
            this._form.reportValidity();
            return;
        }

        if (window.dataLayer) {
            window.dataLayer.push({
                event: 'productEnquiry',
                ecommerce: {
                    items: [{
                        id: this._getFieldValue('product_id'),
                        name: this._getFieldValue('product_name'),
                        product_number: this._getFieldValue('product_number'),
                        variant: this._getFieldValue('product_option'),
                    }],
                },
            });
        }

        this._submit();
    }

    _submit() {
        const formData = new FormData(this._form);

        fetch(this._form.action, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((res) => res.json())
            .then((data) => this._handleResponse(data))
            .catch(() => this._showAlert('danger', 'Prišlo je do nepričakovane napake. Prosimo, poskusite znova.'));
    }

    _handleResponse(response) {
        if (response.fieldErrors) {
            this._handleFieldErrors(response.fieldErrors);
        }

        const alerts = response.alerts ?? [];

        if (response.type === 'success') {
            const message = alerts[0]?.content ?? '';
            this._wrapper.innerHTML = `<div class="enquiry-success"><p>${message}</p></div>`;
            return;
        }

        alerts.forEach((alert) => {
            this._showAlert(alert.type, alert.content);
        });
    }

    _showAlert(type, message) {
        const existing = this._wrapper.querySelector('.enquiry-inline-alert');
        if (existing) existing.remove();
        this._wrapper.insertAdjacentHTML('afterbegin', `<div class="alert alert-${type} enquiry-inline-alert">${message}</div>`);
    }

    _handleFieldErrors(fieldErrors) {
        let firstErrorField = null;

        Object.entries(fieldErrors).forEach(([fieldName, errorMessage]) => {
            const field = this._form.querySelector(`[name="${fieldName}"]`);
            if (!field) return;

            field.classList.add('is-invalid');
            field.classList.remove('is-valid');
            field.setAttribute('aria-invalid', 'true');
            field.dataset.serverError = 'true';
            field.setCustomValidity(errorMessage);

            let feedback = field.parentElement?.querySelector('.invalid-feedback');
            if (!feedback) {
                feedback = document.createElement('div');
                feedback.className = 'invalid-feedback';
                field.insertAdjacentElement('afterend', feedback);
            }
            feedback.textContent = errorMessage;
            feedback.style.display = 'block';

            if (!firstErrorField) firstErrorField = field;
        });

        if (firstErrorField) {
            firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstErrorField.focus();
        }
    }

    _getFieldValue(name) {
        return this._form.querySelector(`input[name="${name}"]`)?.value ?? '';
    }
}
