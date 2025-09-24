<?php declare(strict_types=1);

namespace OptiwebTheme\Storefront\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class JsonDecodeExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('json_decode', [$this, 'decode'])
        ];
    }

    /** @return mixed */
    public function decode(?string $json): mixed
    {
        if (!$json) {
            return null;
        }
        try {
            return \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }
}
