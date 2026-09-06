<?php
declare(strict_types=1);

namespace OptiwebTheme\Core\Content\Cms\DataResolvers;

use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Content\Media\MediaDefinition;

class MainSliderResolver extends AbstractCmsElementResolver
{
    public function getType(): string
    {
        return 'main-slider';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        $images = [];
        $slides = $this->getSlides($slot);

        foreach ($slides as $slide) {
            if (!empty($slide["image"]["value"])) {
                $images[] = $slide["image"]["value"];
            }
        }

        if(empty($images)) return null;

        $criteria = new Criteria($images);
        $criteria->addAssociation("media");
        $criteriaCollection = new CriteriaCollection();
        $criteriaCollection->add('media', MediaDefinition::class, $criteria);

        return $criteriaCollection;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $images = $result->get("media");
        $slides = $this->getSlides($slot);

        $assocImages = [];
        if ($images) {
            $assocImages = $images->reduce(function ($assocMedia, $image) {
                $assocMedia[$image->getId()] = $image;
                return $assocMedia;
            }, []);
        }

        foreach ($slides as $index => $slide) {
            $mediaId = $slide["image"]["value"] ?? null;
            $slides[$index]["image"] = $mediaId !== null ? ($assocImages[$mediaId] ?? null) : null;
        }

        // Set the data even when no slide has an image yet — otherwise a slider whose
        // slides only carry text would render as an empty slider.
        $slot->setData(new ArrayStruct(["slides" => $slides]));
    }


    private function getSlides(CmsSlotEntity $slot): array
    {
        $config = $slot->getFieldConfig();
        $slidesConfig = $config->get("slides");
        return $slidesConfig->getValue();
    }
}
