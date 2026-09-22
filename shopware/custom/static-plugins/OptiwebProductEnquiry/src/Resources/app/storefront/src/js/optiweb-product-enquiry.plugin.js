import Plugin from 'src/plugin-system/plugin.class';

export default class OptiwebProductEnquiryPlugin extends Plugin {

    init() {
        this._wrapper = this.el;
        this._form = this.el.querySelector('form') ?? this.el.closest('form');
        if (!this._form) return;

        this._form.addEventListener('submit', (e) => this._onSubmit(e));
        this._initFieldValidation();
        this._bindCaptchaSubmit();
    }

    /**
     * Captcha compatibility (Settings → Basic information → Captcha).
     *
     * Shopware's Google reCAPTCHA v2/v3 and basic-captcha plugins take over the submit
     * event: they fetch a token (or pre-check the code) asynchronously and then finish
     * with a native `form.submit()` — core forms get away with that because a
     * FormAjaxSubmit / FormCmsHandler plugin sits on them, this one posts by `fetch`.
     * A native submit here would navigate the browser to the JSON endpoint, so the
     * form's own `submit()` is routed into `_submit()` instead. Every captcha flow
     * (v2 checkbox, v2 invisible, v3, basic) ends in that call, so none needs its own
     * case. The honeypot is a plain field and simply travels in the FormData.
     */
    _bindCaptchaSubmit() {
        this._form.submit = () => this._submit();
    }

    /** True when a captcha plugin will call `form.submit()` itself once it is ready. */
    _hasAsyncCaptcha() {
        return !!this._form.querySelector(
            '[data-google-re-captcha-v2], [data-google-re-captcha-v3], [data-basic-captcha]'
        );
    }

    /**
     * A reCAPTCHA token is single-use and Google has already verified it by the time the
     * server answers, so every failed attempt needs a fresh one. v3 fetches a new token
     * on each submit by itself; the v2 widget and the basic captcha image are reset here.
     */
    _resetCaptchas() {
        const pluginManager = window.PluginManager;

        this._form.querySelectorAll('[data-google-re-captcha-v2]').forEach((el) => {
            const plugin = pluginManager.getPluginInstanceFromElement(el, 'GoogleReCaptchaV2');

            if (plugin && plugin.grecaptchaWidgetId !== null && window.grecaptcha) {
                window.grecaptcha.reset(plugin.grecaptchaWidgetId);
                plugin.grecaptchaInput.value = '';
                plugin.currentToken = null;
            }
        });

        this._form.querySelectorAll('[data-basic-captcha]').forEach((el) => {
            const plugin = pluginManager.getPluginInstanceFromElement(el, 'BasicCaptcha');
            const input = el.querySelector('input[name="shopware_basic_captcha_confirm"]');

            if (input) input.value = '';
            plugin?._onLoadBasicCaptcha();
        });
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
        if (this._isSubmitting) {
            return;
        }
        if (!this._form.checkValidity()) {
            this._form.reportValidity();
            return;
        }

        // The captcha plugin has seen this same submit event and calls `form.submit()`
        // (→ `_submit()`) once its token or code check is in.
        if (this._hasAsyncCaptcha()) {
            return;
        }

        this._submit();
    }

    _submit() {
        if (this._isSubmitting) {
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

        const formData = new FormData(this._form);

        this._setLoading(true);

        fetch(this._form.action, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((res) => {
                // Honeypot and reCAPTCHA v3 failures are thrown by core's
                // CaptchaRouteListener (`shouldBreak()`), which answers 403 with an
                // HTML error page, not JSON.
                if (res.status === 403) {
                    throw new Error('captcha');
                }

                return res.json();
            })
            .then((data) => this._handleResponse(data))
            .catch((error) => {
                this._resetCaptchas();
                this._showAlert('danger', error?.message === 'captcha' ? this._errorText('captcha') : this._errorText('unexpected'));
            })
            .finally(() => this._setLoading(false));
    }

    /**
     * Blocks the submit button while the request is in flight so an impatient second
     * click cannot fire the enquiry twice.
     */
    _setLoading(isLoading) {
        const button = this._form.querySelector('button[type="submit"], input[type="submit"]');
        if (!button) return;

        this._isSubmitting = isLoading;
        button.disabled = isLoading;
        button.classList.toggle('is-loading', isLoading);
        this._form.classList.toggle('is-submitting', isLoading);
    }

    _handleResponse(response) {
        // A failed captcha never reaches the controller: core's ErrorController answers
        // with a list of `{ type, error: 'invalid_captcha', alert: '<rendered alert>' }`.
        if (Array.isArray(response)) {
            const alert = response.find((entry) => entry.alert);

            this._resetCaptchas();

            if (alert) {
                this._showAlertHtml(alert.alert);
            } else {
                this._showAlert('danger', this._errorText('captcha'));
            }

            return;
        }

        if (response.type !== 'success') {
            this._resetCaptchas();
        }

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

    _showAlertHtml(html) {
        const existing = this._wrapper.querySelector('.enquiry-inline-alert');
        if (existing) existing.remove();
        this._wrapper.insertAdjacentHTML('afterbegin', `<div class="enquiry-inline-alert">${html}</div>`);
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

    /** Translated messages, printed onto the <form> as `data-error-*` by the template. */
    _errorText(key) {
        const attr = key === 'captcha' ? 'errorCaptcha' : 'errorUnexpected';

        return this._form.dataset[attr] || 'Prišlo je do nepričakovane napake. Prosimo, poskusite znova.';
    }

    _getFieldValue(name) {
        return this._form.querySelector(`input[name="${name}"]`)?.value ?? '';
    }
}
