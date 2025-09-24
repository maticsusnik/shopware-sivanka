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
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;

class CategorySliderResolver extends AbstractCmsElementResolver
{
    public function __construct(
        protected EntityRepository $categoryRepository
    ) {
    }

    public function getType(): string
    {
        return 'category-selection';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        $mode = $this->getSelectionMode($slot);

        // parent (single): children of selected parent
        if ($mode === 'parent') {
            $parentId = $this->getSingleCategoryId($slot);
            if (!$parentId) {
                return null;
            }

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('parentId', $parentId));
            $criteria->addAssociation('media');
            $criteria->addAssociation('seoUrls');

            $collection = new CriteriaCollection();
            $collection->add('categories', CategoryDefinition::class, $criteria);

            return $collection;
        }

        // multiple: fetch the selected categories themselves
        $ids = $this->getMultipleCategoryIds($slot);
        if (empty($ids)) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->setIds($ids);
        $criteria->addAssociation('media');
        $criteria->addAssociation('seoUrls');

        $collection = new CriteriaCollection();
        $collection->add('categories', CategoryDefinition::class, $criteria);

        return $collection;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameters)
     */
    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $entitiesResult = $result->get('categories');
        if (!$entitiesResult) {
            return;
        }

        $domain = $resolverContext->getSalesChannelContext()->getSalesChannel()->getDomains()->first();
        $baseUrl = $domain ? rtrim((string) $domain->getUrl(), '/') : '';

        $items = [];
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

        // Keep the same key your Twig consumes
        $slot->setData(new ArrayStruct(['childCategories' => $items]));
    }

    // -----------------
    // Helpers
    // -----------------

    private function getSelectionMode(CmsSlotEntity $slot): string
    {
        $config = $slot->getFieldConfig();
        $mode = $config->get('selectionMode')?->getValue();

        return $mode === 'multiple' ? 'multiple' : 'parent';
    }

    private function getSingleCategoryId(CmsSlotEntity $slot): ?string
    {
        $config = $slot->getFieldConfig();
        $categoryConfig = $config->get('category');

        $value = $categoryConfig?->getValue();
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @return array<string>
     */
    private function getMultipleCategoryIds(CmsSlotEntity $slot): array
    {
        $config = $slot->getFieldConfig();
        $categoriesConfig = $config->get('categories');

        $value = $categoriesConfig?->getValue();
        if (is_array($value)) {
            // sanitize to strings
            return array_values(array_filter(array_map(
                static fn ($v) => is_string($v) ? $v : null,
                $value
            )));
        }

        return [];
    }
}
