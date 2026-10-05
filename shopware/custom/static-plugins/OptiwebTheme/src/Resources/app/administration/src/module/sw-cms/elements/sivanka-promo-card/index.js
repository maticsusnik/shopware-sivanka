import './component';
import './config';
import './preview';

Shopware.Service('cmsService').registerCmsElement({
    name: 'sivanka-promo-card',
    label: 'sw-cms.elements.sivankaPromoCard.label',
    component: 'sw-cms-el-sivanka-promo-card',
    configComponent: 'sw-cms-el-config-sivanka-promo-card',
    previewComponent: 'sw-cms-el-preview-sivanka-promo-card',
    defaultConfig: {
        media: { source: 'static', value: null, entity: { name: 'media' } },
        imageFocus: { source: 'static', value: 'right' },
        title: { source: 'static', value: 'Naslov promocije' },
        text: { source: 'static', value: 'En stavek, ki pove, za kaj gre.' },
        buttonLabel: { source: 'static', value: 'Poglej ponudbo' },
        url: { source: 'static', value: '' },
    },
});
