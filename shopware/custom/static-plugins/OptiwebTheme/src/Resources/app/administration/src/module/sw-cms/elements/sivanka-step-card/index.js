import './component';
import './config';
import './preview';

Shopware.Service('cmsService').registerCmsElement({
    name: 'sivanka-step-card',
    label: 'sw-cms.elements.sivankaStepCard.label',
    component: 'sw-cms-el-sivanka-step-card',
    configComponent: 'sw-cms-el-config-sivanka-step-card',
    previewComponent: 'sw-cms-el-preview-sivanka-step-card',
    defaultConfig: {
        number: { source: 'static', value: '1' },
        title: { source: 'static', value: 'Naslov koraka' },
        text: { source: 'static', value: 'Kaj se zgodi v tem koraku.' },
    },
});
