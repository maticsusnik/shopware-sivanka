import './component';
import './preview';
import './config';

Shopware.Service('cmsService').registerCmsElement({
    name: "image-tab",
    label: "Image tab settings",
    component: 'sw-cms-el-image-tab',
    configComponent: 'sw-cms-el-config-image-tab',
    previewComponent: 'sw-cms-el-preview-image-tab',
    removable:false,
    hidden:true,
    defaultConfig: {
        tabs: {
            source: 'static',
            value: []
        },
        // Plain string: the config field binds `title.value` to a text field and the
        // storefront prints it directly, so an object here renders as "Array".
        title: {
            source: 'static',
            value: 'Lorem ipsum dolor sit amet'
        },
        update: {
            source: 'static',
            value: ''
        }
    }
});
