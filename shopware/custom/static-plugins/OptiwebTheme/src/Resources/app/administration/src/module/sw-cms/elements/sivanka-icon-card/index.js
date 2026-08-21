import './component';
import './config';
import './preview';

Shopware.Service('cmsService').registerCmsElement({
    name: 'sivanka-icon-card',
    label: 'sw-cms.elements.sivankaIconCard.label',
    component: 'sw-cms-el-sivanka-icon-card',
    configComponent: 'sw-cms-el-config-sivanka-icon-card',
    previewComponent: 'sw-cms-el-preview-sivanka-icon-card',
    defaultConfig: {
        icon: { source: 'static', value: 'ruler' },
        title: { source: 'static', value: 'Naslov kartice' },
        text: { source: 'static', value: 'Opis v enem ali dveh stavkih.' },
        variant: { source: 'static', value: 'card' },
    },
});
