<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Core\Content\ProductEnquiry;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ProductEnquiryEntity>
 */
class ProductEnquiryCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ProductEnquiryEntity::class;
    }
}
