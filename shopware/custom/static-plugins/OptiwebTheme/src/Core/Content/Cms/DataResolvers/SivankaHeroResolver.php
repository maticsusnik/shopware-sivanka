<?php
declare(strict_types=1);

namespace OptiwebTheme\Core\Content\Cms\DataResolvers;

use OptiwebTheme\Core\Content\Cms\CmsLink;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;

/**
 * Home hero — reference/Sivanka Home v2 redesign.dc.html, section 1.
 *
 * Two photographs (left 42%, right 42%), centred copy, and a four-item trust strip
 * that overlaps the band. `backgroundImage` is the pre-v2 single-image key and is
 * still read as a fallback for `imageLeft`.
 */
class SivankaHeroResolver extends AbstractCmsElementResolver
{
    private const TRUST_SLOTS = [1, 2, 3, 4];

    public function __construct(private readonly CmsLink $link)
    {
    }

    public function getType(): string
    {
        return 'sivanka-hero';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        $ids = array_values(array_filter([
            $this->mediaId($slot, 'imageLeft') ?? $this->mediaId($slot, 'backgroundImage'),
            $this->mediaId($slot, 'imageRight'),
        ]));

        if ($ids === []) {
            return null;
        }

        $criteriaCollection = new CriteriaCollection();
        $criteriaCollection->add('media', MediaDefinition::class, new Criteria($ids));

        return $criteriaCollection;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $config = $slot->getFieldConfig();
        $media = $result->get('media');

        $data = [
            'imageLeft' => $this->media($media, $this->mediaId($slot, 'imageLeft') ?? $this->mediaId($slot, 'backgroundImage')),
            'imageRight' => $this->media($media, $this->mediaId($slot, 'imageRight')),
            'headline' => $config->get('headline')?->getValue() ?? '',
            'subheadline' => $config->get('subheadline')?->getValue() ?? '',
            'primaryBtnLabel' => $config->get('primaryBtnLabel')?->getValue() ?? '',
            'primaryBtnUrl' => $this->link->url($config->get('primaryBtnUrl')?->getValue()),
            'secondaryBtnLabel' => $config->get('secondaryBtnLabel')?->getValue() ?? '',
            'secondaryBtnUrl' => $this->link->url($config->get('secondaryBtnUrl')?->getValue()),
        ];

        foreach (self::TRUST_SLOTS as $i) {
            $data['trust' . $i . 'Icon'] = $config->get('trust' . $i . 'Icon')?->getValue() ?? '';
            $data['trust' . $i . 'Title'] = $config->get('trust' . $i . 'Title')?->getValue() ?? '';
            $data['trust' . $i . 'Sub'] = $config->get('trust' . $i . 'Sub')?->getValue() ?? '';
        }

        $slot->setData(new ArrayStruct($data));
    }

    private function mediaId(CmsSlotEntity $slot, string $key): ?string
    {
        $value = $slot->getFieldConfig()->get($key)?->getValue();

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function media(?EntityCollection $collection, ?string $id): ?MediaEntity
    {
        if ($collection === null || $id === null) {
            return null;
        }

        $entity = $collection->get($id);

        return $entity instanceof MediaEntity ? $entity : null;
    }
}
