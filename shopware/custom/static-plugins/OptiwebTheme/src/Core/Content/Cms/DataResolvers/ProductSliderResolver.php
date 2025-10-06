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
use Shopware\Core\Content\Product\SalesChannel\ProductListResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;

class ProductSliderResolver extends AbstractCmsElementResolver
{
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
        $criteria->setLimit(10);

        $hasProducts = false;
        if ($selectionMode === 'manual') {
            $ids = (array) ($config->get('productIds')?->getValue() ?? []);
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

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        /** @var ProductListResponse|mixed $products */
        $products = $result->get('products');
        /** @var \Shopware\Core\Content\Media\MediaEntity|null $media */
        $media = $result->get('media') ? $result->get('media')->first() : null;

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




