<?php
declare(strict_types=1);

namespace OptiwebTheme\Core\Content\Cms\DataResolvers;

use Shopware\Core\Content\Category\Service\NavigationLoaderInterface;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;


class CategoryNavigationResolver extends AbstractCmsElementResolver
{

    public function __construct(
        private NavigationLoaderInterface $navigationLoader,
        private EntityRepository $categoryRepository
    ) {
    }

    public function getType(): string
    {
        return 'category-navigation';
    }

    /**
     * @codeCoverageIgnore
     */
    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        return null;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $salesChannelContext = $resolverContext->getSalesChannelContext();
        $salesChannel = $salesChannelContext->getSalesChannel();

        $rootNavigationId = $salesChannel->getNavigationCategoryId();
        $servicesNavigationId = $salesChannel->getServiceCategoryId();
        $skipParentCategories = [$servicesNavigationId, $rootNavigationId];
        $navigationId = $resolverContext->getRequest()->get('navigationId', $rootNavigationId);

        /**
         * only show child categories for the current category
         */
        $tree = $this->navigationLoader->load(
            $navigationId,
            $salesChannelContext,
            $navigationId,
            $salesChannel->getNavigationCategoryDepth()
        );

        /**
         * add "back" link to parent category
         */
        $parentId = $tree->getActive()->getParentId() ?? null;
        $parentCategory = $parentId && !in_array($parentId, $skipParentCategories) ? $this->categoryRepository->search((new Criteria([$parentId])), $salesChannelContext->getContext())->first() : null;

        /**
         * show sibling categories for deepest levels with no child categories
         */
        if ($parentCategory && $tree->getActive()->getChildCount() === 0) {
            $tree = $this->navigationLoader->load(
                $navigationId,
                $salesChannelContext,
                $parentId,
                $salesChannel->getNavigationCategoryDepth()
            );
        }

        /**
         * Navigation title
         */
        $navigationTitle = $tree->getActive()->getCustomFields()['categoryGridTitle'] ?? null;
        $navigationTitle = $navigationTitle && !empty(trim(strip_tags($navigationTitle))) ? $navigationTitle : null;

        $slot->setData(new ArrayStruct(['children' => $tree, 'parent' => $parentCategory, 'navigationTitle' => $navigationTitle]));
    }

}
