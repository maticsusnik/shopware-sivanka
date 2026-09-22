<?php declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use OptiwebTheme\Core\Content\Cms\CmsLink;
use OptiwebTheme\Core\Content\Cms\DataResolvers\BannersResolver;
use OptiwebTheme\Core\Content\Cms\DataResolvers\CatalogsResolver;
use OptiwebTheme\Core\Content\Cms\DataResolvers\CategoryNavigationResolver;
use OptiwebTheme\Core\Content\Cms\DataResolvers\CategoryReadMoreResolver;
use OptiwebTheme\Core\Content\Cms\DataResolvers\CategorySliderResolver;
use OptiwebTheme\Core\Content\Cms\DataResolvers\ImageTabsResolver;
use OptiwebTheme\Core\Content\Cms\DataResolvers\MainSliderResolver;
use OptiwebTheme\Core\Content\Cms\DataResolvers\ProductSliderResolver;
use OptiwebTheme\Core\Content\Cms\DataResolvers\SivankaHeroResolver;
use OptiwebTheme\Core\Content\Cms\DataResolvers\SivankaNavigationResolver;
use OptiwebTheme\Core\Content\Cms\DataResolvers\SivankaPromoCardResolver;
use OptiwebTheme\Core\Content\Cms\DataResolvers\SivankaServiceCardResolver;
use OptiwebTheme\Core\Content\Product\SalesChannel\CrossSelling\FallbackCrossSellingRoute;
use OptiwebTheme\Services\ShippingMethodRouteDecorator;
use OptiwebTheme\Storefront\Subscriber\CartLineItemAddedEvent;
use OptiwebTheme\Storefront\Subscriber\HeaderMenuSubscriber;
use OptiwebTheme\Storefront\Subscriber\ProductAvailabilitySubscriber;
use OptiwebTheme\Storefront\Twig\GlobalTwigVariables;
use OptiwebTheme\Storefront\Twig\JsonDecodeExtension;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Shipping\SalesChannel\ShippingMethodRoute;
use Shopware\Core\Content\Category\Service\NavigationLoader;
use Shopware\Core\Content\Media\Core\Application\AbstractMediaUrlGenerator;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\ProductCrossSellingRoute;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\Rule\RuleIdMatcher;
use Shopware\Core\Framework\Util\HtmlSanitizer;
use Shopware\Core\System\SystemConfig\SystemConfigService;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set(CmsLink::class)
        ->args([service(SeoUrlPlaceholderHandlerInterface::class)]);

    // CMS data resolvers
    $services->set(MainSliderResolver::class)
        ->tag('shopware.cms.data_resolver');

    $services->set(ImageTabsResolver::class)
        ->tag('shopware.cms.data_resolver');

    $services->set(CatalogsResolver::class)
        ->tag('shopware.cms.data_resolver');

    $services->set(BannersResolver::class)
        ->tag('shopware.cms.data_resolver');

    $services->set(ProductSliderResolver::class)
        ->tag('shopware.cms.data_resolver');

    $services->set(CategorySliderResolver::class)
        ->args([service(AbstractMediaUrlGenerator::class)])
        ->tag('shopware.cms.data_resolver');

    $services->set(CategoryReadMoreResolver::class)
        ->args([service(HtmlSanitizer::class)])
        ->tag('shopware.cms.data_resolver');

    $services->set(CategoryNavigationResolver::class)
        ->args([
            service(NavigationLoader::class),
            service('sales_channel.category.repository'),
        ])
        ->tag('shopware.cms.data_resolver');

    $services->set(SivankaNavigationResolver::class)
        ->tag('shopware.cms.data_resolver');

    $services->set(SivankaHeroResolver::class)
        ->args([service(CmsLink::class)])
        ->tag('shopware.cms.data_resolver');

    $services->set(SivankaServiceCardResolver::class)
        ->tag('shopware.cms.data_resolver');

    $services->set(SivankaPromoCardResolver::class)
        ->tag('shopware.cms.data_resolver');

    // Subscribers
    $services->set(HeaderMenuSubscriber::class)
        ->args([service(NavigationLoader::class)])
        ->tag('kernel.event_subscriber');

    $services->set(CartLineItemAddedEvent::class)
        ->args([service(SystemConfigService::class)])
        ->tag('kernel.event_subscriber');

    $services->set(ProductAvailabilitySubscriber::class)
        ->args([service('category.repository')])
        ->tag('kernel.event_subscriber');

    // Twig
    $services->set(GlobalTwigVariables::class)
        ->private()
        ->args([
            service('request_stack'),
            service(SystemConfigService::class),
        ])
        ->tag('twig.extension');

    $services->set(JsonDecodeExtension::class)
        ->tag('twig.extension');

    // Decorators
    $services->set(FallbackCrossSellingRoute::class)
        ->decorate(ProductCrossSellingRoute::class)
        ->args([
            service(FallbackCrossSellingRoute::class . '.inner'),
            service('sales_channel.product.repository'),
            'Sorodni izdelki',
        ]);

    $services->set(ShippingMethodRouteDecorator::class)
        ->decorate(ShippingMethodRoute::class)
        ->args([
            service(ShippingMethodRouteDecorator::class . '.inner'),
            service(CartService::class),
            service(DeliveryCalculator::class),
            service(RuleIdMatcher::class),
        ]);
};
