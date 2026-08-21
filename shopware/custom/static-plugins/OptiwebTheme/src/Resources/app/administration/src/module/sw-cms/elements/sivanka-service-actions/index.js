import './component';
import './config';
import './preview';

Shopware.Service('cmsService').registerCmsElement({
    name: 'sivanka-service-actions',
    label: 'sw-cms.elements.sivankaServiceActions.label',
    component: 'sw-cms-el-sivanka-service-actions',
    configComponent: 'sw-cms-el-config-sivanka-service-actions',
    previewComponent: 'sw-cms-el-preview-sivanka-service-actions',
    defaultConfig: {
        primaryLabel: { source: 'static', value: 'Obiščite trgovino' },
        primaryUrl: { source: 'static', value: '' },
        secondaryLabel: { source: 'static', value: 'Pokličite nas' },
        secondaryUrl: { source: 'static', value: '' },
        newTab: { source: 'static', value: false },
    },
});
