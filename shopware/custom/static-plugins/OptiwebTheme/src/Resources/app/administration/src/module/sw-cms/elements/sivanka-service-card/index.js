import './component';
import './config';
import './preview';

Shopware.Service('cmsService').registerCmsElement({
    name: 'sivanka-service-card',
    label: 'sw-cms.elements.sivankaServiceCard.label',
    component: 'sw-cms-el-sivanka-service-card',
    configComponent: 'sw-cms-el-config-sivanka-service-card',
    previewComponent: 'sw-cms-el-preview-sivanka-service-card',
    defaultConfig: {
        media: {
            source: 'static',
            value: null,
            entity: {
                name: 'media',
            },
        },
        icon: { source: 'static', value: 'needle' },
        title: { source: 'static', value: 'Ime storitve' },
        text: { source: 'static', value: 'Kratek opis storitve.' },
        meta: { source: 'static', value: '' },
        url: { source: 'static', value: '' },
        linkLabel: { source: 'static', value: 'Več' },
        variant: { source: 'static', value: 'wide' },
    },
});
