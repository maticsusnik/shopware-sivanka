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

class SivankaHeroResolver extends AbstractCmsElementResolver
{
    public function getType(): string
    {
        return 'sivanka-hero';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        $config = $slot->getFieldConfig();
        $mediaId = $config->get('backgroundImage')?->getValue();

        if (empty($mediaId)) {
            return null;
        }

        $criteria = new Criteria([$mediaId]);
        $criteriaCollection = new CriteriaCollection();
        $criteriaCollection->add('media', MediaDefinition::class, $criteria);

        return $criteriaCollection;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $config = $slot->getFieldConfig();

        $backgroundImage = null;
        $media = $result->get('media');
        if ($media) {
            foreach ($media as $entity) {
                if ($entity instanceof MediaEntity) {
                    $backgroundImage = $entity;
                    break;
                }
            }
        }

        $slot->setData(new ArrayStruct([
            'backgroundImage'  => $backgroundImage,
            'headline'         => $config->get('headline')?->getValue() ?? '',
            'headlineEmphasis' => $config->get('headlineEmphasis')?->getValue() ?? '',
            'subheadline'      => $config->get('subheadline')?->getValue() ?? '',
            'primaryBtnLabel'  => $config->get('primaryBtnLabel')?->getValue() ?? '',
            'primaryBtnUrl'    => $config->get('primaryBtnUrl')?->getValue() ?? '',
            'secondaryBtnLabel'=> $config->get('secondaryBtnLabel')?->getValue() ?? '',
            'secondaryBtnUrl'  => $config->get('secondaryBtnUrl')?->getValue() ?? '',
            'trust1Icon'       => $config->get('trust1Icon')?->getValue() ?? 'users',
            'trust1Title'      => $config->get('trust1Title')?->getValue() ?? '',
            'trust1Sub'        => $config->get('trust1Sub')?->getValue() ?? '',
            'trust2Icon'       => $config->get('trust2Icon')?->getValue() ?? 'truck',
            'trust2Title'      => $config->get('trust2Title')?->getValue() ?? '',
            'trust2Sub'        => $config->get('trust2Sub')?->getValue() ?? '',
            'trust3Icon'       => $config->get('trust3Icon')?->getValue() ?? 'shield',
            'trust3Title'      => $config->get('trust3Title')?->getValue() ?? '',
            'trust3Sub'        => $config->get('trust3Sub')?->getValue() ?? '',
        ]));
    }
}
