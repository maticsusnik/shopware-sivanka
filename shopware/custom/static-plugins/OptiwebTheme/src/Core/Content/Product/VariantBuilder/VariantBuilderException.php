<?php declare(strict_types=1);

namespace OptiwebTheme\Core\Content\Product\VariantBuilder;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

class VariantBuilderException extends HttpException
{
    public static function invalid(string $reason): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            'OPTIWEB_VARIANT_BUILDER__' . strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $reason)),
            'Variants cannot be created: {{ reason }}',
            ['reason' => $reason],
        );
    }
}
