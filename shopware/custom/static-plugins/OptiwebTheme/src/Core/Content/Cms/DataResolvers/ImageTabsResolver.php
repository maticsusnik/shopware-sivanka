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

class ImageTabsResolver extends AbstractCmsElementResolver
{
    public function getType(): string
    {
        return 'image-tab';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        $images = [];
        $tabs = $this->getTabs($slot);

        foreach ($tabs as $tab) {
            if (!empty($tab["image"]["value"])) {
                $images[] = $tab["image"]["value"];
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
        $tabs = $this->getTabs($slot);

        if(!$images) return;

        $assocImages = $images->reduce(function ($assocMedia, $image) {
            $assocMedia[$image->getId()] = $image;
            return $assocMedia;
        }, []);

        usort($tabs, function ($a, $b) {
            $orderA = $a['order'] ?? 0;
            $orderB = $b['order'] ?? 0;
            return $orderB <=> $orderA;
        });



        foreach ($tabs as $index => $tab) {
            if (!isset($tab["image"]["value"])) continue;
            if (!isset($assocImages[$tab["image"]["value"]])) continue;
            $tabs[$index]["image"] = $assocImages[$tab["image"]["value"]];
        }


        $slot->setData(new ArrayStruct(["tabs" => $tabs]));
    }


    private function getTabs(CmsSlotEntity $slot): array
    {
        $config = $slot->getFieldConfig();
        $tabsConfig = $config->get("tabs");
        return $tabsConfig->getValue();
    }
}
