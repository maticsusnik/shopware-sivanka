<?php
declare(strict_types=1);

namespace OptiwebTheme\Core\Content\Cms\DataResolvers;

use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;

class ProductSliderResolver extends AbstractCmsElementResolver
{
    private const LIMIT = 10;

    public function __construct(private readonly SalesChannelRepository $productRepository)
    {
    }

    public function getType(): string
    {
        return 'sivanka-product-slider';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        $config = $slot->getFieldConfig();
        $selectionMode = (string) ($config->get('selectionMode')?->getValue() ?? 'manual');

        $criteriaCollection = new CriteriaCollection();
        $criteria = new Criteria();
        $criteria->addAssociation('cover');
        $criteria->setLimit(self::LIMIT);

        $hasProducts = false;
        if ($selectionMode === 'manual') {
            $ids = (array) ($config->get('productIds')?->getValue() ?? []);
            if (!empty($ids)) {
                $criteria->setIds($ids);
                $hasProducts = true;
            }
        } elseif ($selectionMode === 'random') {
            $ids = $this->randomProductIds($config->get('parentCategoryId')?->getValue(), $resolverContext);
            if (!empty($ids)) {
                $criteria->setIds($ids);
                $hasProducts = true;
            }
        } else {
            $parentCategoryId = (string) ($config->get('parentCategoryId')?->getValue() ?? '');
            if ($parentCategoryId !== '') {
                $criteria->addFilter(new EqualsFilter('product.visibilities.visibility', ProductVisibilityDefinition::VISIBILITY_ALL));
                $criteria->addFilter(new EqualsFilter('product.visibilities.salesChannelId', $resolverContext->getSalesChannelContext()->getSalesChannelId()));
                $criteria->addFilter(new EqualsFilter('product.categoriesRo.id', $parentCategoryId));
                $hasProducts = true;
            }
        }

        if ($hasProducts) {
            $criteriaCollection->add('products', ProductDefinition::class, $criteria);
        }

        // Add media criteria if image is set
        $imageId = $config->get('image')?->getValue();
        if ($imageId) {
            $mediaCriteria = new Criteria([$imageId]);
            $criteriaCollection->add('media', \Shopware\Core\Content\Media\MediaDefinition::class, $mediaCriteria);
        }

        return $criteriaCollection->all() ? $criteriaCollection : null;
    }

    /**
     * LIMIT random products — from the category when one is chosen, from the
     * whole catalogue otherwise. The DAL has no random sorting, so all visible
     * ids are fetched (cheap: ids only) and sampled here. Variants are left out
     * so one article cannot fill the slider twice.
     *
     * @return array<int, string>
     */
    private function randomProductIds(mixed $categoryId, ResolverContext $resolverContext): array
    {
        $salesChannelContext = $resolverContext->getSalesChannelContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('product.parentId', null));
        $criteria->addFilter(new EqualsFilter('product.visibilities.visibility', ProductVisibilityDefinition::VISIBILITY_ALL));
        $criteria->addFilter(new EqualsFilter('product.visibilities.salesChannelId', $salesChannelContext->getSalesChannelId()));
        if (is_string($categoryId) && $categoryId !== '') {
            $criteria->addFilter(new EqualsFilter('product.categoriesRo.id', $categoryId));
        }

        $ids = $this->productRepository->searchIds($criteria, $salesChannelContext)->getIds();
        if (count($ids) <= self::LIMIT) {
            return array_values($ids);
        }

        $keys = array_rand($ids, self::LIMIT);
        shuffle($keys);

        return array_map(static fn ($key) => $ids[$key], $keys);
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        // The slot data carries the entity collection (not the search result): an
        // EntitySearchResult is no longer an EntityCollection as of Shopware 6.8.
        $products = $result->get('products')?->getEntities();
        /** @var \Shopware\Core\Content\Media\MediaEntity|null $media */
        $media = $result->get('media')?->getEntities()->first();

        $config = $slot->getFieldConfig();
        $title = $config->get('title')?->getValue()['text'] ?? '';

        $data = new ArrayStruct([
            'products' => $products,
            'leftImage' => $media,
            'sliderConfig' => [
                'title' => ['value' => $title],
                'elMinWidth' => ['value' => ''],
                'border' => ['value' => false],
                'navigationArrows' => ['value' => 'outside'],
                'navigation' => ['value' => true],
                'addToCartButtons' => ['value' => true],
            ],
        ]);

        $slot->setData($data);
    }
}




