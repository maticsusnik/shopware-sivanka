<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Core\Content\ProductEnquiry;

enum ProductEnquiryStatus: string
{
    case New = 'new';
    case Read = 'read';
    // Set by hand in the admin once the enquiry has been answered
    case Done = 'done';
}
