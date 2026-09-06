<?php declare(strict_types=1);

namespace OptiwebTheme\Core\Content\Cms\DataResolvers;

use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;

/**
 * Loads the optional media entity of the "sivanka-promo-card" CMS element.
 * All other fields of the element are plain static config and are read from
 * `element.fieldConfig` directly in the storefront template.
 */
class SivankaPromoCardResolver extends AbstractCmsElementResolver
{
    private const MEDIA_KEY = 'media';

    public function getType(): string
    {
        return 'sivanka-promo-card';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        $mediaConfig = $slot->getFieldConfig()->get(self::MEDIA_KEY);

        if ($mediaConfig === null || $mediaConfig->isMapped() || $mediaConfig->getValue() === null) {
            return null;
        }

        $criteriaCollection = new CriteriaCollection();
        $criteriaCollection->add(
            self::MEDIA_KEY . '_' . $slot->getUniqueIdentifier(),
            MediaDefinition::class,
            new Criteria([$mediaConfig->getValue()])
        );

        return $criteriaCollection;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $slot->setData(new ArrayStruct([
            self::MEDIA_KEY => $this->resolveMedia($slot, $result),
        ]));
    }

    private function resolveMedia(CmsSlotEntity $slot, ElementDataCollection $result): ?MediaEntity
    {
        $mediaConfig = $slot->getFieldConfig()->get(self::MEDIA_KEY);

        if ($mediaConfig === null || $mediaConfig->getValue() === null) {
            return null;
        }

        $searchResult = $result->get(self::MEDIA_KEY . '_' . $slot->getUniqueIdentifier());

        if ($searchResult === null) {
            return null;
        }

        $media = $searchResult->get($mediaConfig->getValue());

        return $media instanceof MediaEntity ? $media : null;
    }
}
