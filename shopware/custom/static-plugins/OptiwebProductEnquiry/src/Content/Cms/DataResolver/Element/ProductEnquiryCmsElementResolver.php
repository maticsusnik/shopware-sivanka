<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Content\Cms\DataResolver\Element;

use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\ArrayStruct;

class ProductEnquiryCmsElementResolver extends AbstractCmsElementResolver
{
    public function __construct(
        private readonly EntityRepository $salutationRepository,
        private readonly EntityRepository $productRepository,
    ) {
    }

    public function getType(): string
    {
        return 'product-enquiry-form';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        return null;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('salutationKey', FieldSorting::ASCENDING));

        $salutations = $this->salutationRepository->search(
            $criteria,
            $resolverContext->getSalesChannelContext()->getContext()
        )->getEntities();

        $slot->setData(new ArrayStruct(['salutations' => $salutations]));

        $productId = $resolverContext->getRequest()->query->get('productId');

        if ($productId) {
            $productCriteria = new Criteria([$productId]);
            $productCriteria->addAssociations(['media', 'cover', 'cover.media', 'options', 'options.group']);

            $product = $this->productRepository->search(
                $productCriteria,
                $resolverContext->getSalesChannelContext()->getContext()
            )->first();

            if ($product) {
                $slot->addExtension('product', $product);
            }
        }
    }
}
