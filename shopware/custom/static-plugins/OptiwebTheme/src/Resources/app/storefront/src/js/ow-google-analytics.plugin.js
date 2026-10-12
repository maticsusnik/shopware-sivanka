import GoogleAnalyticsPlugin from 'src/plugin/google-analytics/google-analytics.plugin';

/**
 * Core's init() returns before `this.events` is created while consent is still missing,
 * so rejecting the cookie banner on a first visit called `this.events.forEach` on
 * undefined. Nothing was registered yet, so there is nothing to disable.
 */
export default class OwGoogleAnalyticsPlugin extends GoogleAnalyticsPlugin {

    disableEvents() {
        if (!this.events) {
            return;
        }

        super.disableEvents();
    }
}
