import './component';
import './preview';

const reason = (icon, title, text) => ({
    type: 'sivanka-icon-card',
    default: {
        config: {
            icon: { source: 'static', value: icon },
            title: { source: 'static', value: title },
            text: { source: 'static', value: text },
            variant: { source: 'static', value: 'medallion' },
        },
    },
});

Shopware.Service('cmsService').registerCmsBlock({
    name: 'sivanka-why',
    label: 'sw-cms.blocks.sivanka.sivankaWhy.label',
    category: 'sivanka',
    component: 'sw-cms-block-sivanka-why',
    previewComponent: 'sw-cms-preview-sivanka-why',
    defaultConfig: {
        marginTop: '0px',
        marginBottom: '0px',
        marginLeft: '0px',
        marginRight: '0px',
        sizingMode: 'boxed',
    },
    slots: {
        heading: {
            type: 'sivanka-service-intro',
            default: {
                config: {
                    eyebrow: { source: 'static', value: '' },
                    headline: { source: 'static', value: 'Zakaj izbrati Šivanko?' },
                    lead: { source: 'static', value: '' },
                    headlineLevel: { source: 'static', value: 'h2' },
                    variant: { source: 'static', value: 'home' },
                },
            },
        },
        cardOne: reason('thumbs-up', 'Široka ponudba', 'Vse za ustvarjanje, izdelavo in popravila na enem mestu.'),
        cardTwo: reason('user', 'Strokovno svetovanje', 'Pomagamo pri izbiri in z veseljem svetujemo.'),
        cardThree: reason('heart', 'Za hobi in za poklic', 'Izdelki za ustvarjalce, galanteriste in čevljarje.'),
        cardFour: reason('store', 'Lokalna trgovina', 'Obiščite nas v Kranju — vedno nasmejani in pripravljeni.'),
    },
});
