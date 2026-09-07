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
    /**
     * Category custom field that makes every product below it enquiry-only.
     */
    private const CATEGORY_FIELD_NOT_SELLABLE = 'custom_category_notSellable';

    /**
     * Category custom field that hides the price of every product below it. Hiding a
     * price implies enquiry-only: a shopper must never be able to buy something whose
     * price the shop refuses to show.
     */
    private const CATEGORY_FIELD_HIDE_PRICE = 'custom_category_hidePrice';

    private const PRODUCT_FIELD_NOT_SELLABLE = 'custom_product_notSellable';

    private const PRODUCT_FIELD_HIDE_PRICE = 'custom_product_hidePrice';

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
        $hidePriceCategoryIds = [];
        if (!empty($categoryIds)) {
            $criteria = new Criteria($categoryIds);
            $categories = $this->categoryRepository->search($criteria, $context)->getEntities();

            // Create a map of category IDs that are not sellable / price-less
            foreach ($categories as $category) {
                if (!$category instanceof CategoryEntity) {
                    continue;
                }

                $customFields = $category->getCustomFields();
                if (!$customFields) {
                    continue;
                }

                if (($customFields[self::CATEGORY_FIELD_NOT_SELLABLE] ?? false) === true) {
                    $notSellableCategoryIds[] = $category->getId();
                }

                if (($customFields[self::CATEGORY_FIELD_HIDE_PRICE] ?? false) === true) {
                    $hidePriceCategoryIds[] = $category->getId();
                }
            }
        }

        // Mark products as not available and set custom field for templates
        foreach ($entities as $product) {
            if (!$product instanceof SalesChannelProductEntity) {
                continue;
            }

            $isNotSellable = false;
            $isHidePrice = false;

            // Check product's own custom fields
            $customFields = $product->getCustomFields() ?? [];
            if (($customFields[self::PRODUCT_FIELD_NOT_SELLABLE] ?? false) === true) {
                $isNotSellable = true;
            }

            if (($customFields[self::PRODUCT_FIELD_HIDE_PRICE] ?? false) === true) {
                $isHidePrice = true;
            }

            // A product the ERP sync left without a price must never be buyable for 0,00 €
            if (!$isNotSellable && $this->isFreeOfCharge($product, $event->getSalesChannelContext())) {
                $isNotSellable = true;
            }

            // Check if product belongs to a not-sellable / hidden-price category (using categoryTree)
            $categoryTree = $product->getCategoryTree();
            if (is_array($categoryTree) && $categoryTree !== []) {
                if (!$isNotSellable && $this->inAnyCategory($categoryTree, $notSellableCategoryIds)) {
                    $isNotSellable = true;
                }

                if (!$isHidePrice && $this->inAnyCategory($categoryTree, $hidePriceCategoryIds)) {
                    $isHidePrice = true;
                }
            }

            // A hidden price means the product cannot be bought either — the templates
            // then swap "add to cart" for the enquiry button off `_isNotSellable`.
            if ($isHidePrice) {
                $isNotSellable = true;
            }

            // Set custom fields on product so templates can easily check them
            $customFields['_isNotSellable'] = $isNotSellable;
            $customFields['_isHidePrice'] = $isHidePrice;
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
     * @param array<string> $categoryTree
     * @param array<string> $flaggedCategoryIds
     */
    private function inAnyCategory(array $categoryTree, array $flaggedCategoryIds): bool
    {
        if ($flaggedCategoryIds === []) {
            return false;
        }

        foreach ($categoryTree as $categoryId) {
            if (in_array($categoryId, $flaggedCategoryIds, true)) {
                return true;
            }
        }

        return false;
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
