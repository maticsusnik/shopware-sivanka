import OptiwebProductEnquiryPlugin from './js/optiweb-product-enquiry.plugin';
import OptiwebWishlistEnquiryPlugin from './js/optiweb-wishlist-enquiry.plugin';

window.PluginManager.register(
    'OptiwebProductEnquiry',
    OptiwebProductEnquiryPlugin,
    '[data-optiweb-product-enquiry]'
);

window.PluginManager.register(
    'OptiwebWishlistEnquiry',
    OptiwebWishlistEnquiryPlugin,
    '[data-optiweb-wishlist-enquiry]'
);
