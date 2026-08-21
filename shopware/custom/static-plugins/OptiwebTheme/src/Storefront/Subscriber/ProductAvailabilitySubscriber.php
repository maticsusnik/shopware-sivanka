<?php declare(strict_types=1);

namespace OptiwebTheme\Storefront\Subscriber;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductDefinition;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelEntityLoadedEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductAvailabilitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $categoryRepository
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'sales_channel.product.loaded' => 'onProductsLoaded',
        ];
    }

    public function onProductsLoaded(SalesChannelEntityLoadedEvent $event): void
    {
        // Only process product entities
        // if (!$event->getDefinition() instanceof SalesChannelProductDefinition) {
        //     return;
        // }

        $entities = $event->getEntities();
        
        if (empty($entities)) {
            return;
        }

        $context = $event->getContext();

        // Collect all category IDs from products using categoryTree (includes all parent categories)
        $categoryIds = [];
        foreach ($entities as $product) {
            if (!$product instanceof SalesChannelProductEntity) {
                continue;
            }

            // Get category tree which includes all category IDs including parent categories
            $categoryTree = $product->getCategoryTree();
            if ($categoryTree && is_array($categoryTree)) {
                $categoryIds = array_merge($categoryIds, $categoryTree);
            }
        }

        // Remove duplicates
        $categoryIds = array_unique($categoryIds);

        // Load categories with their custom fields if we have any category IDs
        $notSellableCategoryIds = [];
        if (!empty($categoryIds)) {
            $criteria = new Criteria($categoryIds);
            $categories = $this->categoryRepository->search($criteria, $context)->getEntities();

            // Create a map of category IDs that are not sellable
            foreach ($categories as $category) {
                if (!$category instanceof CategoryEntity) {
                    continue;
                }
                
                $customFields = $category->getCustomFields();
                if ($customFields && ($customFields['custom_category_notSellable'] ?? false) === true) {
                    $notSellableCategoryIds[] = $category->getId();
                }
            }
        }

        // Mark products as not available and set custom field for templates
        foreach ($entities as $product) {
            if (!$product instanceof SalesChannelProductEntity) {
                continue;
            }

            $isNotSellable = false;

            // Check product's own custom field
            $customFields = $product->getCustomFields() ?? [];
            if (($customFields['custom_product_notSellable'] ?? false) === true) {
                $isNotSellable = true;
            }

            // A product the ERP sync left without a price must never be buyable for 0,00 €
            if (!$isNotSellable && $this->isFreeOfCharge($product, $event->getSalesChannelContext())) {
                $isNotSellable = true;
            }

            // Check if product belongs to a not-sellable category (using categoryTree)
            if (!$isNotSellable && !empty($notSellableCategoryIds)) {
                $categoryTree = $product->getCategoryTree();
                if ($categoryTree && is_array($categoryTree)) {
                    foreach ($categoryTree as $categoryId) {
                        if (in_array($categoryId, $notSellableCategoryIds, true)) {
                            $isNotSellable = true;
                            break;
                        }
                    }
                }
            }

            // Set custom field on product so templates can easily check it
            $customFields['_isNotSellable'] = $isNotSellable;
            $product->assign(['customFields' => $customFields]);

            // If product is not sellable, set availableStock to 0 to make it unavailable
            if ($isNotSellable) {
                $product->assign([
                    'availableStock' => 0,
                    'available' => false,
                ]);
            }
        }
    }

    /**
     * True when the product carries no usable price. The Minimax sync leaves `price`
     * at 0 for items it could not price, and without this guard those products render
     * a working "add to cart" button and can be ordered for free.
     */
    private function isFreeOfCharge(SalesChannelProductEntity $product, SalesChannelContext $context): bool
    {
        // `calculatedPrice` is a typed property that is only set once the price
        // calculator has run, so it has to be probed rather than read directly.
        if ($product->has('calculatedPrice')) {
            $calculated = $product->getCalculatedPrice();

            return $calculated->getTotalPrice() <= 0.0;
        }

        $price = $product->getPrice()?->getCurrencyPrice($context->getCurrencyId());

        return $price !== null && $price->getGross() <= 0.0;
    }
}

