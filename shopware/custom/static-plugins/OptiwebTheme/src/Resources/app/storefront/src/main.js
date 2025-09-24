import ProductSliderDesktopPlugin from './js/product-slider-desktop.plugin';
import OffcanversMenuDesktopPlugin from './js/off-canvas-menu-desktop.plugin';
import OffcanversMenuMobilePlugin from './js/off-canvas-menu-mobile.plugin';
import OptiwebFaqPlugin from "./js/faq.plugin";
import OffcanvasCustomPlugin from "./js/offcanvas-custom.plugin";
import ReadMore from './js/read-more.plugin';
import CategoryView from './js/category-view.plugin';
import CategoryFiltersShowMorePlugin from './js/category-filters-show-more.plugin';
import FilterToggleCheckboxesPlugin from "./js/filter-toggle-checkboxes";
import StickyBuyBox from "./js/sticky-buy-box";
// import AddAllToCart from "./js/add-all-to-cart";

const PluginManager = window.PluginManager;

PluginManager.register('ProductSliderDesktop', ProductSliderDesktopPlugin, '[data-product-slider-desktop]');
PluginManager.register('OffcanversMenuDesktop', OffcanversMenuDesktopPlugin, '[data-off-canvas-menu-desktop]');
PluginManager.register('OffcanversMenuMobile', OffcanversMenuMobilePlugin, '[data-off-canvas-menu-mobile]');
PluginManager.register('OptiwebFaqPlugin', OptiwebFaqPlugin, ".ow-faq");
PluginManager.register('OffCanvasFilterPlugin', OffcanvasCustomPlugin, '[data-off-canvas-custom]');
PluginManager.register('ReadMore', ReadMore, '[data-ow-read-more]');
PluginManager.register('CategoryView', CategoryView, '[data-category-view]');
PluginManager.register('CategoryFiltersShowMorePlugin', CategoryFiltersShowMorePlugin,'[data-off-canvas-filter-content]');
PluginManager.deregister("FilterPropertySelect", "[data-filter-property-select]");
PluginManager.register('FilterPropertySelectPlugin', FilterToggleCheckboxesPlugin,  '[data-filter-property-select]');
PluginManager.register('StickyBuyBox', StickyBuyBox,  '[data-sticky-buy-box]');
// PluginManager.register('AddAllToCart', AddAllToCart,  '[data-add-all-to-cart]');




