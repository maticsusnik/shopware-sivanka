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
use Shopware\Core\Content\Media\Core\Application\AbstractMediaUrlGenerator;
use Shopware\Core\Content\Media\Core\Params\UrlParams;
use Shopware\Core\Content\Media\Core\Params\UrlParamsSource;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;

class CategorySliderResolver extends AbstractCmsElementResolver
{
    public function __construct(private readonly AbstractMediaUrlGenerator $mediaUrlGenerator)
    {
    }

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
                    'category_icon'     => $this->resolveIcon($category),
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

    /**
     * The `categoryIcon` custom field holds a snapshot of the media entity taken in the
     * administration, so its `url` is frozen to whatever domain was configured at upload
     * time (it still pointed at the old dev host). Only `path` is stable, so rebuild the
     * absolute url from it through the media url generator.
     *
     * @return array<string, mixed>|null
     */
    private function resolveIcon(CategoryEntity $category): ?array
    {
        $icon = $category->getCustomFields()['categoryIcon'] ?? null;

        if (!is_array($icon)) {
            return null;
        }

        $path = $icon['path'] ?? null;
        if (!is_string($path) || $path === '') {
            // no usable path -> drop the stale absolute url rather than hotlinking a foreign host
            unset($icon['url']);

            return $icon;
        }

        $id = $icon['id'] ?? $path;
        $updatedAt = $this->parseDate($icon['updatedAt'] ?? null) ?? $this->parseDate($icon['createdAt'] ?? null);

        $urls = $this->mediaUrlGenerator->generate([
            $id => new UrlParams((string) $id, UrlParamsSource::MEDIA, $path, $updatedAt),
        ]);

        $icon['url'] = $urls[$id] ?? null;

        return $icon;
    }

    private function parseDate(mixed $value): ?\DateTimeInterface
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

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
