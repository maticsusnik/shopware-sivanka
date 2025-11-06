import OptiwebProductEnquiryPlugin from './js/optiweb-product-enquiry.plugin';

// Register the plugin
const PluginManager = window.PluginManager;
PluginManager.register('OptiwebProductEnquiry', OptiwebProductEnquiryPlugin, '[data-optiweb-product-enquiry]');
