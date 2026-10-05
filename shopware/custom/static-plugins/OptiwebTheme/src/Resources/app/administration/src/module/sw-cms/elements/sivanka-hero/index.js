import './component';
import './config';
import './preview';
import { emptyLink } from '../../link-value';

Shopware.Service('cmsService').registerCmsElement({
    name: 'sivanka-hero',
    label: 'sw-cms.elements.sivankaHero.label',
    component: 'sw-cms-el-sivanka-hero',
    configComponent: 'sw-cms-el-config-sivanka-hero',
    previewComponent: 'sw-cms-el-preview-sivanka-hero',
    removable: false,
    hidden: true,
    defaultConfig: {
        imageLeft: { source: 'static', value: null, entity: { name: 'media' } },
        imageRight: { source: 'static', value: null, entity: { name: 'media' } },
        headline: { source: 'static', value: 'Vse za ustvarjanje,\nizdelavo in popravila.' },
        subheadline: {
            source: 'static',
            value: 'Od preje in sukancev do usnja, kovinske galanterije in materialov za mojstre — vse za vaše ideje in delo.',
        },
        primaryBtnLabel: { source: 'static', value: 'Razišči ponudbo' },
        primaryBtnUrl: { source: 'static', value: emptyLink() },
        secondaryBtnLabel: { source: 'static', value: 'O nas' },
        secondaryBtnUrl: { source: 'static', value: emptyLink() },
        trust1Icon: { source: 'static', value: 'truck' },
        trust1Title: { source: 'static', value: 'Hitra dostava' },
        trust1Sub: { source: 'static', value: 'po vsej Sloveniji' },
        trust2Icon: { source: 'static', value: 'store' },
        trust2Title: { source: 'static', value: 'Osebni prevzem' },
        trust2Sub: { source: 'static', value: 'v trgovini v Kranju' },
        trust3Icon: { source: 'static', value: 'chat' },
        trust3Title: { source: 'static', value: 'Strokovno svetovanje' },
        trust3Sub: { source: 'static', value: 'z veseljem pomagamo' },
        trust4Icon: { source: 'static', value: 'heart' },
        trust4Title: { source: 'static', value: 'Kakovostni materiali' },
        trust4Sub: { source: 'static', value: 'za ustvarjanje in obrt' },
    },
});
