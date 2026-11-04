<?php declare(strict_types=1);

namespace OptiwebTheme\Storefront\Subscriber;

use Shopware\Core\Content\Product\Events\ProductCrossSellingIdsCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductCrossSellingStreamCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSliderStaticCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSliderStreamCriteriaEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Product rows outside the listing (cross-selling "related products", the core CMS product slider) show
 * only buyable articles: simple products and variants, never a variant parent. A parent (`parent_…`) is
 * an empty shell the catalogue import created to hold its variants; listings already show each variant
 * as its own card, and these rows should match.
 */
class NoParentProductsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProductCrossSellingIdsCriteriaEvent::class => 'excludeParents',
            ProductCrossSellingStreamCriteriaEvent::class => 'excludeParents',
            ProductSliderStaticCriteriaEvent::class => 'excludeParents',
            ProductSliderStreamCriteriaEvent::class => 'excludeParents',
        ];
    }

    public function excludeParents(
        ProductCrossSellingIdsCriteriaEvent|ProductCrossSellingStreamCriteriaEvent|ProductSliderStaticCriteriaEvent|ProductSliderStreamCriteriaEvent $event
    ): void {
        $event->getCriteria()->addFilter(self::filter());
    }

    /**
     * childCount is 0 on simple products and variants, and above 0 only on parents.
     */
    public static function filter(): EqualsFilter
    {
        return new EqualsFilter('product.childCount', 0);
    }
}
