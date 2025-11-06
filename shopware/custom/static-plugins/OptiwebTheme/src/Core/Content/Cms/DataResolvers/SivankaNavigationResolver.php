<?php
declare(strict_types=1);

namespace OptiwebTheme\Core\Content\Cms\DataResolvers;

use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\EntityResolverContext;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Util\AfterSort;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Struct\StructCollection;


class SivankaNavigationResolver extends AbstractCmsElementResolver
{

    public function getType(): string
    {
        return 'sivanka-category-navigation';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        $config = $slot->getConfig();

        // Try to get category from entity context if available
        $categoryId = null;
        $categoryChildCount = 0;
        if ($resolverContext instanceof EntityResolverContext) {
            $entity = $resolverContext->getEntity();
            if ($entity && method_exists($entity, 'get')) {
                $categoryChildCount = $entity->get('childCount') ?? 0;
                $categoryId = $categoryChildCount > 0 ? $entity->get('id') : $entity->get('parentId');
            }
        }

        // Fallback to config or request
        $categoryId = $config["parentCategory"]["value"] ?? $categoryId ?? $resolverContext->getRequest()->get('navigationId');
        
        $criteria = new Criteria();
        if(!$categoryId){
            return null;
        }
        $criteria->addFilter(new EqualsFilter('parentId', $categoryId));
        $criteria->addFilter(new EqualsFilter('active', 1));
        $criteria->addFilter(new EqualsFilter('visible', 1));
        // $criteria->addSorting(new FieldSorting('autoIncrement'));
        $criteria->addSorting(new FieldSorting('afterCategoryId', FieldSorting::ASCENDING));
        $criteria->addAssociation("media");
        $criteriaCollection = new CriteriaCollection();
        $criteriaCollection->add('navigation', CategoryDefinition::class, $criteria);

        if(!$categoryId) {
            return $criteriaCollection;
        }

        $criteria = new Criteria([$categoryId]);
        $criteria->addAssociation("media");

        $criteriaCollection->add('parentCategory', CategoryDefinition::class, $criteria);

        return $criteriaCollection;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameters)
     */
    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {

        $data = new StructCollection();
        if ($result->get('navigation')) {

            // $data->set('navigation', $result->get('navigation'));

            $categories = $result->get('navigation');
            $categories = AfterSort::sort($categories->getElements(), "afterCategoryId");
            $data->set('navigation', new ArrayStruct($categories));

        }
        if ($result->get('parentCategory')) {
            $data->set('parentCategory', $result->get('parentCategory'));
        }
        $slot->setData($data);
    }
}

