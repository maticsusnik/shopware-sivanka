import './acl';
import './page/optiweb-notice-bar-index';

const { Module } = Shopware;

/**
 * Vsebine > Obvestilo — a one-page module for the storefront notice bar.
 *
 * The settings live in the system-config domain OptiwebTheme.noticeBar
 * (Resources/config/noticeBar.xml), not in the plugin configuration, so shop staff
 * can be given the "Obvestilo" role without access to extensions or theme settings.
 */
Module.register('optiweb-notice-bar', {
    type: 'plugin',
    name: 'optiweb-notice-bar',
    title: 'optiweb-notice-bar.general.title',
    description: 'optiweb-notice-bar.general.description',
    color: '#ff68b4',
    icon: 'regular-bell',
    snippets: {
        'sl-SI': () => import('./snippet/sl-SI.json'),
        'en-GB': () => import('./snippet/en-GB.json'),
    },
    routes: {
        index: {
            component: 'optiweb-notice-bar-index',
            path: 'index',
            meta: {
                privilege: 'optiweb_notice_bar.viewer',
            },
        },
    },
    navigation: [
        {
            id: 'optiweb-notice-bar',
            label: 'optiweb-notice-bar.general.title',
            color: '#ff68b4',
            path: 'optiweb.notice.bar.index',
            icon: 'regular-bell',
            parent: 'sw-content',
            privilege: 'optiweb_notice_bar.viewer',
            position: 100,
        },
    ],
});
