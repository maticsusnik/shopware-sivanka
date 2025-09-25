<?php
declare(strict_types=1);

namespace OptiwebTheme\Core\Content\Cms\DataResolvers;

use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;

class CategorySliderResolver extends AbstractCmsElementResolver
{
    public function getType(): string
    {
        return 'category-selection';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        $collection = new CriteriaCollection();

        // --- Categories (logic unchanged) ---
        $mode = $this->getSelectionMode($slot); // 'single' | 'multiple'

        if ($mode === 'single') {
            $parentId = $this->getSingleCategoryId($slot); // parentCategoryId
            if ($parentId) {
                $criteria = (new Criteria())
                    ->addFilter(new EqualsFilter('parentId', $parentId))
                    ->addAssociation('media')
                    ->addAssociation('seoUrls');

                $collection->add('categories', CategoryDefinition::class, $criteria);
            }
        } else { // multiple
            $ids = $this->getMultipleCategoryIds($slot); // categories[]
            if (!empty($ids)) {
                $criteria = (new Criteria())
                    ->setIds($ids)
                    ->addAssociation('media')
                    ->addAssociation('seoUrls');

                $collection->add('categories', CategoryDefinition::class, $criteria);
            }
        }

        // --- Optional left image ---
        if ($imageId = $this->getImageId($slot)) {
            $mediaCriteria = (new Criteria())
                ->setIds([$imageId])
                ->addAssociation('thumbnails');

            $collection->add('image', MediaDefinition::class, $mediaCriteria);
        }

        // If nothing to resolve, return null (use has()/count() instead of getElements())
        // if (!$collection->has('categories') && !$collection->has('image')) {
        //     return null;
        // }

        return $collection;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $domain = $resolverContext->getSalesChannelContext()->getSalesChannel()->getDomains()->first();
        $baseUrl = $domain ? rtrim((string) $domain->getUrl(), '/') : '';

        // Categories
        $items = [];
        $entitiesResult = $result->get('categories');
        if ($entitiesResult) {
            /** @var CategoryEntity $category */
            foreach ($entitiesResult->getEntities() as $category) {
                $translated = $category->getTranslated();
                $seoPath = $category->getSeoUrls()->first()?->getSeoPathInfo() ?? '';
                $link = $seoPath ? $baseUrl . '/' . ltrim($seoPath, '/') : $baseUrl;

                $items[] = [
                    'name'              => $translated['name'] ?? '',
                    'short_description' => $translated['short_description'] ?? '',
                    'link'              => $link,
                    'media'             => $category->getMedia(),
                    'category_icon'     => $category->getCustomFields()['categoryIcon'] ?? null,
                ];
            }
        }

        // Optional left image
        /** @var MediaEntity|null $leftImage */
        $leftImage = null;
        if ($imageResult = $result->get('image')) {
            $leftImage = $imageResult->getEntities()->first();
        }

        $slot->setData(new ArrayStruct([
            'childCategories' => $items,
            'leftImage'       => $leftImage, // MediaEntity|null
        ]));
    }

    // -----------------
    // Helpers (match new config keys)
    // -----------------

    private function getSelectionMode(CmsSlotEntity $slot): string
    {
        $config = $slot->getFieldConfig();
        $mode = $config->get('selectionMode')?->getValue();

        return $mode === 'multiple' ? 'multiple' : 'single';
    }

    private function getSingleCategoryId(CmsSlotEntity $slot): ?string
    {
        $config = $slot->getFieldConfig();
        $value = $config->get('parentCategoryId')?->getValue();

        return (is_string($value) && $value !== '') ? $value : null;
    }

    /**
     * @return array<string>
     */
    private function getMultipleCategoryIds(CmsSlotEntity $slot): array
    {
        $config = $slot->getFieldConfig();
        $value = $config->get('categories')?->getValue();

        if (!is_array($value)) {
            return [];
        }

        // sanitize to strings
        return array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? $v : null,
            $value
        )));
    }

    private function getImageId(CmsSlotEntity $slot): ?string
    {
        $config = $slot->getFieldConfig();
        $value = $config->get('image')?->getValue();

        return (is_string($value) && $value !== '') ? $value : null;
    }
}
