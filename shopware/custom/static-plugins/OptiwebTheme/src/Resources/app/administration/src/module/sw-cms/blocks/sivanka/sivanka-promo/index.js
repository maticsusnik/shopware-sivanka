import './component';
import './preview';

const promoCard = (title, text, buttonLabel, focus) => ({
    type: 'sivanka-promo-card',
    default: {
        config: {
            media: { source: 'static', value: null, entity: { name: 'media' } },
            imageFocus: { source: 'static', value: focus },
            title: { source: 'static', value: title },
            text: { source: 'static', value: text },
            buttonLabel: { source: 'static', value: buttonLabel },
            url: { source: 'static', value: '' },
        },
    },
});

Shopware.Service('cmsService').registerCmsBlock({
    name: 'sivanka-promo',
    label: 'sw-cms.blocks.sivanka.sivankaPromo.label',
    category: 'sivanka',
    component: 'sw-cms-block-sivanka-promo',
    previewComponent: 'sw-cms-preview-sivanka-promo',
    defaultConfig: {
        marginTop: '0px',
        marginBottom: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed',
    },
    slots: {
        promoOne: promoCard('Jesenske novosti', 'Sveže barve preje za pletenje in kvačkanje.', 'Odkrij novosti', 'right'),
        promoTwo: promoCard('Usnje in kovinska galanterija', 'Vse za izdelavo in popravila na enem mestu.', 'Poglej ponudbo', 'right'),
        promoThree: promoCard('Darilni bon Šivanka', 'Naj izberejo sami. Vedno prava izbira.', 'Podari ustvarjalnost', 'center'),
    },
});
