<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Core\Content\ProductEnquiry;

enum ProductEnquiryStatus: string
{
    case New = 'new';
    case Read = 'read';
}
