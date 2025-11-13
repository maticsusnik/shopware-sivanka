<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Content\Cms\DataResolver\Element;

use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class ProductEnquiryCmsElementResolver extends AbstractCmsElementResolver
{
    public function __construct(
        private readonly EntityRepository $salutationRepository,
        private readonly EntityRepository $productRepository
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
        $context = $resolverContext->getSalesChannelContext();

        // Get salutations
        $salutations = $this->salutationRepository->search(new Criteria(), $context->getContext())->getEntities();
        $slot->setData($salutations);

        // Add product if productId is provided
        $productId = $resolverContext->getRequest()->get('productId');
        
        if ($productId) {
            $product = $this->getProductById($productId, $context->getContext());
            
            if ($product) {
                $slot->addExtension('product', $product);
            }
        }
    }

    private function getProductById(string $productId, \Shopware\Core\Framework\Context $context): ?object
    {
        $criteria = new Criteria();
        // $criteria->addAssociation('categories');
        $criteria->addAssociation('media');
        $criteria->addAssociation('cover');
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('options');
        // $criteria->addAssociation('unit');
        // $criteria->addAssociation('manufacturer');
        $criteria->addFilter(new EqualsFilter('product.id', $productId));

        return $this->productRepository
            ->search($criteria, $context)
            ->getEntities()
            ->first();
    }
}
