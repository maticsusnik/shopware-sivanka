/**
 * "Obvestilo" role under Vsebine in Users & permissions.
 *
 * Saving goes through /api/_action/system-config, which only checks the generic
 * system_config privileges — so an editor can technically write other config keys
 * through the API. The admin UI only ever shows them the notice bar domain.
 */
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'content',
    key: 'optiweb_notice_bar',
    roles: {
        viewer: {
            privileges: [
                'system_config:read',
                'sales_channel:read',
            ],
            dependencies: [],
        },
        editor: {
            privileges: [
                'system_config:update',
                'system_config:create',
                'system_config:delete',
            ],
            dependencies: [
                'optiweb_notice_bar.viewer',
            ],
        },
    },
});
