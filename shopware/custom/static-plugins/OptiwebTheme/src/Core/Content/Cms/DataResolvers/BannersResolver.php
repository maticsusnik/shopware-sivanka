<?php
declare(strict_types=1);

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

class BannersResolver extends AbstractCmsElementResolver
{
    public function getType(): string
    {
        return 'banners';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        $config = $slot->getFieldConfig();
        $bannersConfig = $config->get('banners');
        if ($bannersConfig === null || empty($bannersConfig->getValue())) {
            return null;
        }

        $mediaIds = [];
        $banners = (array) $bannersConfig->getValue();
        foreach ($banners as $banner) {
            if (!empty($banner['mediaId'])) {
                $mediaIds[] = $banner['mediaId'];
            }
        }

        if (empty($mediaIds)) {
            return null;
        }

        $criteria = new Criteria($mediaIds);
        $criteriaCollection = new CriteriaCollection();
        $criteriaCollection->add('media', MediaDefinition::class, $criteria);

        return $criteriaCollection;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $config = $slot->getFieldConfig();

        $sectionTitle = $config->get('sectionTitle')?->getValue() ?? '';
        $perRow = (int) ($config->get('perRow')?->getValue() ?? 3);
        $bannersConfig = (array) ($config->get('banners')?->getValue() ?? []);

        /** @var iterable<MediaEntity>|null $media */
        $media = $result->get('media');
        $mediaById = [];
        if ($media) {
            foreach ($media as $entity) {
                if ($entity instanceof MediaEntity) {
                    $mediaById[$entity->getId()] = $entity;
                }
            }
        }

        $resolvedBanners = [];
        foreach ($bannersConfig as $banner) {
            $mediaId = $banner['mediaId'] ?? null;
            $resolvedBanners[] = [
                'mediaId' => $mediaId,
                'media' => $mediaId && isset($mediaById[$mediaId]) ? $mediaById[$mediaId] : null,
                'text' => $banner['text'] ?? '',
                'link' => $banner['link'] ?? '',
                'textPositionV' => $banner['textPositionV'] ?? 'bottom',
                'textPositionH' => $banner['textPositionH'] ?? 'left',
            ];
        }

        $data = new ArrayStruct([
            'sectionTitle' => $sectionTitle,
            'perRow' => $perRow,
            'banners' => $resolvedBanners,
        ]);

        $slot->setData($data);
    }
}


