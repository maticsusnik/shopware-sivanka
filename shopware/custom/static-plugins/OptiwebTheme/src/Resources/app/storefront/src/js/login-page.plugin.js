import Plugin from 'src/plugin-system/plugin.class';

export default class LoginPagePlugin extends Plugin {
    static options = {
        loginTabSelector: '[data-login-tab-link]',
        registerTabSelector: '[data-register-tab-link]',
        loginTabPaneSelector: '#login-tab-pane',
        registerTabPaneSelector: '#register-tab-pane'
    };

    init() {
        this._registerEvents();
    }

    _registerEvents() {
        const loginTab = this.el.querySelector(this.constructor.options.loginTabSelector);
        const registerTab = this.el.querySelector(this.constructor.options.registerTabSelector);

        if (loginTab) {
            loginTab.addEventListener('click', this._onLoginTabClick.bind(this));
        }

        if (registerTab) {
            registerTab.addEventListener('click', this._onRegisterTabClick.bind(this));
        }
    }

    _onLoginTabClick(event) {
        event.preventDefault();
        this._switchToTab('login');
    }

    _onRegisterTabClick(event) {
        event.preventDefault();
        this._switchToTab('register');
    }

    _switchToTab(tab) {
        const loginTab = this.el.querySelector(this.constructor.options.loginTabSelector);
        const registerTab = this.el.querySelector(this.constructor.options.registerTabSelector);
        const loginPane = this.el.querySelector(this.constructor.options.loginTabPaneSelector);
        const registerPane = this.el.querySelector(this.constructor.options.registerTabPaneSelector);

        if (tab === 'login') {
            loginTab?.classList.add('active');
            loginTab?.setAttribute('aria-selected', 'true');
            registerTab?.classList.remove('active');
            registerTab?.setAttribute('aria-selected', 'false');
            loginPane?.classList.add('active', 'show');
            registerPane?.classList.remove('active', 'show');
        } else {
            registerTab?.classList.add('active');
            registerTab?.setAttribute('aria-selected', 'true');
            loginTab?.classList.remove('active');
            loginTab?.setAttribute('aria-selected', 'false');
            registerPane?.classList.add('active', 'show');
            loginPane?.classList.remove('active', 'show');
        }
    }
}

