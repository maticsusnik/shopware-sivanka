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


class CatalogsResolver extends AbstractCmsElementResolver
{
    public function getType(): string
    {
        return 'catalogs';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        $images = [];
        $catalogs = $this->getCatalogs($slot);

        foreach ($catalogs as $catalog) {
            if (!empty($catalog["image"]["value"])) {
                $images[] = $catalog["image"]["value"];
            }
        }

        if (empty($images)) return null;

        $criteria = new Criteria($images);
        $criteria->addAssociation("media");
        $criteriaCollection = new CriteriaCollection();
        $criteriaCollection->add('media', MediaDefinition::class, $criteria);

        return $criteriaCollection;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $images = $result->get("media");
        $catalogs = $this->getCatalogs($slot);

        if (!$images) return;

        $assocImages = $images->reduce(function ($assocMedia, $image) {
            $assocMedia[$image->getId()] = $image;

            return $assocMedia;
        }, []);

        foreach ($catalogs as $index => $catalog) {
            if (!isset($catalog["image"]["value"]) || !isset($assocImages[$catalog["image"]["value"]])) {
                $catalogs[$index]["image"] = null;
                continue;
            }
            $catalogs[$index]["image"] = $assocImages[$catalog["image"]["value"]];
        }

        $slot->setData(new ArrayStruct(["catalogsData" => $catalogs]));
    }

    private function getCatalogs(CmsSlotEntity $slot): array
    {
        $config = $slot->getFieldConfig();
        $config = $config->get("catalogs");

        return $config->getValue();
    }
}
