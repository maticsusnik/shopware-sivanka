<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Core\Content\ProductEnquiryLine;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ProductEnquiryLineEntity>
 */
class ProductEnquiryLineCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ProductEnquiryLineEntity::class;
    }
}
