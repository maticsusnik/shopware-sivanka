import Plugin from 'src/plugin-system/plugin.class';
import CookieStorageHelper from 'src/helper/storage/cookie-storage.helper';

/**
 * GA4 `sign_up` and `login` for the register and login forms.
 *
 * Core's SignUpEvent/LoginEvent (6.7.14) wait for `beforeSubmit` from the
 * FormValidation plugin, but core's own register and login forms carry
 * `data-form-handler` instead, so those two events never fire. This listens to
 * FormHandler's `validSubmit` (a DOM event on the form) instead. If a form ever
 * gets `data-form-validation` again, core tracks it and this stays out of the way.
 *
 * A guest checkout (no account created) is not a sign-up and is not tracked.
 */
export default class GaAccountEventsPlugin extends Plugin {

    init() {
        if (this.el.hasAttribute('data-form-validation')) {
            return;
        }

        this.el.addEventListener('validSubmit', () => this._onValidSubmit());
    }

    _onValidSubmit() {
        if (this.el.classList.contains('login-form')) {
            this._track('login', { method: 'mail' });

            return;
        }

        if (this.el.classList.contains('register-form') && this._createsAccount()) {
            this._track('sign_up', { method: 'mail' });
        }
    }

    /**
     * `createCustomerAccount` is a checkbox on the checkout register form (unchecked =
     * guest checkout) and a hidden `1` on the account page.
     */
    _createsAccount() {
        const field = this.el.querySelector('input[name="createCustomerAccount"]');

        if (!field) {
            return true;
        }

        return field.type === 'checkbox' ? field.checked : field.value === '1';
    }

    /** Same gate as core's google-analytics.plugin.js: GA active and, with the default banner, consented. */
    _track(eventName, params) {
        if (!window.gtagActive || typeof window.gtag !== 'function') {
            return;
        }

        if (window.useDefaultCookieConsent
            && !CookieStorageHelper.getItem('google-analytics-enabled')
            && !CookieStorageHelper.getItem('google-ads-enabled')) {
            return;
        }

        window.gtag('event', eventName, params);
    }
}
