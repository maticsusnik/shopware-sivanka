<?php
declare(strict_types=1);

namespace OptiwebTheme\Core\Content\Product\SalesChannel\CrossSelling;

use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingEntity;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\AbstractProductCrossSellingRoute;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\CrossSellingElement;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\CrossSellingElementCollection;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\ProductCrossSellingRouteResponse;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * "Sorodni izdelki" on the product page.
 *
 * The design shows a related-products row on every product. Shopware only fills one
 * when an editor has configured cross-selling on that product, so this decorator
 * supplies a fallback: the newest products from the same category, minus the product
 * being viewed.
 *
 * Anything configured in the administration wins — the fallback only runs when the
 * inner route comes back with nothing to show, so an editor's own selection is never
 * second-guessed.
 */
class FallbackCrossSellingRoute extends AbstractProductCrossSellingRoute
{
    private const LIMIT = 4;

    /**
     * @param SalesChannelRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly AbstractProductCrossSellingRoute $decorated,
        private readonly SalesChannelRepository $productRepository,
        private readonly string $fallbackName,
    ) {
    }

    public function getDecorated(): AbstractProductCrossSellingRoute
    {
        return $this->decorated;
    }

    public function load(
        string $productId,
        Request $request,
        SalesChannelContext $context,
        Criteria $criteria
    ): ProductCrossSellingRouteResponse {
        $response = $this->decorated->load($productId, $request, $context, $criteria);

        if ($this->hasProducts($response->getResult())) {
            return $response;
        }

        $products = $this->sameCategoryProducts($productId, $context);

        if ($products->count() === 0) {
            return $response;
        }

        return new ProductCrossSellingRouteResponse(
            new CrossSellingElementCollection([$this->element($products)])
        );
    }

    private function hasProducts(CrossSellingElementCollection $elements): bool
    {
        foreach ($elements as $element) {
            if ($element->getTotal() > 0) {
                return true;
            }
        }

        return false;
    }

    private function sameCategoryProducts(string $productId, SalesChannelContext $context): ProductCollection
    {
        $current = $this->productRepository
            ->search(new Criteria([$productId]), $context)
            ->getEntities()
            ->first();

        if ($current === null) {
            return new ProductCollection();
        }

        // `categoriesRo` is the read-only tree that includes inherited assignments, so
        // a variant sitting only on its parent's category still finds neighbours.
        $categoryIds = $current->getCategoryTree() ?? [];

        if ($categoryIds === []) {
            return new ProductCollection();
        }

        $criteria = new Criteria();
        $criteria->setLimit(self::LIMIT);
        $criteria->addAssociation('cover');
        $criteria->addAssociation('manufacturer');
        $criteria->addFilter(new EqualsAnyFilter('product.categoriesRo.id', $categoryIds));
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('product.id', $productId),
        ]));
        $criteria->addFilter(new ProductAvailableFilter(
            $context->getSalesChannelId(),
            ProductVisibilityDefinition::VISIBILITY_LINK
        ));
        $criteria->addSorting(new FieldSorting('product.releaseDate', FieldSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('product.autoIncrement', FieldSorting::DESCENDING));

        return $this->productRepository->search($criteria, $context)->getEntities();
    }

    private function element(ProductCollection $products): CrossSellingElement
    {
        $crossSelling = new ProductCrossSellingEntity();
        $crossSelling->setId('optiweb-related-fallback');
        $crossSelling->setName($this->fallbackName);
        $crossSelling->setTranslated(['name' => $this->fallbackName]);
        $crossSelling->setPosition(1);
        $crossSelling->setActive(true);
        $crossSelling->setLimit(self::LIMIT);

        $element = new CrossSellingElement();
        $element->setCrossSelling($crossSelling);
        $element->setProducts($products);
        $element->setTotal($products->count());

        return $element;
    }
}
