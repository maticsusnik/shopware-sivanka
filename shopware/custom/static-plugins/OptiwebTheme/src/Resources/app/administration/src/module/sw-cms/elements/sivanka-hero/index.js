import './component';
import './config';
import './preview';

Shopware.Service('cmsService').registerCmsElement({
    name: 'sivanka-hero',
    label: 'Sivanka Hero',
    component: 'sw-cms-el-sivanka-hero',
    configComponent: 'sw-cms-el-config-sivanka-hero',
    previewComponent: 'sw-cms-el-preview-sivanka-hero',
    removable: false,
    hidden: true,
    defaultConfig: {
        backgroundImage:   { source: 'static', value: null },
        headline:          { source: 'static', value: 'Vse za ustvarjanje' },
        headlineEmphasis:  { source: 'static', value: 'z ljubeznijo.' },
        subheadline:       { source: 'static', value: 'Premium preje, tekstilni pripomočki in orodja za vaše najlepše ideje.' },
        primaryBtnLabel:   { source: 'static', value: 'Odkrij preje' },
        primaryBtnUrl:     { source: 'static', value: '' },
        secondaryBtnLabel: { source: 'static', value: 'Poišči navdih' },
        secondaryBtnUrl:   { source: 'static', value: '' },
        trust1Icon:        { source: 'static', value: 'users' },
        trust1Title:       { source: 'static', value: '10.000+' },
        trust1Sub:         { source: 'static', value: 'zadovoljnih ustvarjalcev' },
        trust2Icon:        { source: 'static', value: 'truck' },
        trust2Title:       { source: 'static', value: 'Hitra dostava' },
        trust2Sub:         { source: 'static', value: 'po Sloveniji in EU' },
        trust3Icon:        { source: 'static', value: 'shield' },
        trust3Title:       { source: 'static', value: 'Premium kakovost' },
        trust3Sub:         { source: 'static', value: 'preverjeni materiali' },
    }
});
