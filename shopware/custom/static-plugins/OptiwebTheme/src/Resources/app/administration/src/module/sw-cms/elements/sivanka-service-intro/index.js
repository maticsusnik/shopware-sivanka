import './component';
import './config';
import './preview';

Shopware.Service('cmsService').registerCmsElement({
    name: 'sivanka-service-intro',
    label: 'sw-cms.elements.sivankaServiceIntro.label',
    component: 'sw-cms-el-sivanka-service-intro',
    configComponent: 'sw-cms-el-config-sivanka-service-intro',
    previewComponent: 'sw-cms-el-preview-sivanka-service-intro',
    defaultConfig: {
        eyebrow: { source: 'static', value: 'Storitve' },
        headline: { source: 'static', value: 'Naslov storitve' },
        lead: { source: 'static', value: 'Kratek opis storitve v enem ali dveh stavkih.' },
        headlineLevel: { source: 'static', value: 'h1' },
        variant: { source: 'static', value: 'hero' },
    },
});
