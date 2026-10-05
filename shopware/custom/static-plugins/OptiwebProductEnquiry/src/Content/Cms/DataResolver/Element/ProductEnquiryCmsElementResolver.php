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
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

class ProductEnquiryCmsElementResolver extends AbstractCmsElementResolver
{
    /** Upper bound on how many products one enquiry modal will render. */
    private const MAX_PRODUCTS = 100;

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

        $productIds = $this->readProductIds($resolverContext->getRequest());

        if ($productIds === []) {
            return;
        }

        $productCriteria = new Criteria($productIds);
        $productCriteria->addAssociations(['media', 'cover', 'cover.media', 'options', 'options.group']);

        $products = $this->productRepository->search(
            $productCriteria,
            $resolverContext->getSalesChannelContext()->getContext()
        )->getEntities();

        if ($products->count() === 0) {
            return;
        }

        // Keep the requested order — the wishlist hands them over newest first,
        // and the modal should list them the way the customer sees them.
        $ordered = [];
        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            if ($product !== null) {
                $ordered[] = $product;
            }
        }

        // `product` (singular) is what the single-product modal reads; leaving it
        // set keeps the product-detail enquiry working untouched.
        $slot->addExtension('product', $ordered[0]);
        $slot->addExtension('products', new ArrayStruct(['items' => $ordered]));
    }

    /**
     * Product ids the modal was opened for.
     *
     * `productId=<id>` is the product-detail case; `productIds[]=<id>` the
     * wishlist case. Ids are de-duplicated, validated and capped, because they
     * arrive straight off a URL a customer can edit.
     *
     * @return list<string>
     */
    private function readProductIds(Request $request): array
    {
        $ids = $request->query->all()['productIds'] ?? [];

        if (!is_array($ids)) {
            $ids = [];
        }

        $single = $request->query->get('productId');
        if (is_string($single) && $single !== '') {
            array_unshift($ids, $single);
        }

        $valid = [];
        foreach ($ids as $id) {
            if (is_string($id) && Uuid::isValid($id) && !in_array($id, $valid, true)) {
                $valid[] = $id;
            }

            if (count($valid) >= self::MAX_PRODUCTS) {
                break;
            }
        }

        return $valid;
    }
}
