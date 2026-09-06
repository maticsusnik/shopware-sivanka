<?php
declare(strict_types=1);

namespace OptiwebTheme\Core\Content\Cms;

use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;

/**
 * Turns the link value produced by the administration's `ow-url` component into a URL.
 *
 * The stored shape is `{ type, link, entity, entityId, text }` — see
 * `Resources/app/administration/src/module/sw-cms/link-value.js`. Elements that used a
 * plain URL string before switching to `ow-url` still carry that string, so
 * {@see self::normalize()} accepts both.
 *
 * Internal links are emitted as SEO url placeholders, exactly like the `seoUrl()` Twig
 * function does; the storefront replaces them with the sales-channel specific URL when the
 * response is rendered.
 */
final readonly class CmsLink
{
    public const TYPE_EXTERNAL = 'external';
    public const TYPE_INTERNAL = 'internal';

    public const ENTITY_CATEGORY = 'category';
    public const ENTITY_PRODUCT = 'product';

    public function __construct(private SeoUrlPlaceholderHandlerInterface $seoUrlReplacer)
    {
    }

    /**
     * @return array{type: string, link: string|null, entity: string|null, entityId: string|null, text: string}
     */
    public static function normalize(mixed $value): array
    {
        if (\is_string($value)) {
            return [
                'type' => self::TYPE_EXTERNAL,
                'link' => $value !== '' ? $value : null,
                'entity' => null,
                'entityId' => null,
                'text' => '',
            ];
        }

        if (!\is_array($value)) {
            $value = [];
        }

        $type = $value['type'] ?? self::TYPE_EXTERNAL;

        return [
            'type' => $type === self::TYPE_INTERNAL ? self::TYPE_INTERNAL : self::TYPE_EXTERNAL,
            'link' => \is_string($value['link'] ?? null) && $value['link'] !== '' ? $value['link'] : null,
            'entity' => \is_string($value['entity'] ?? null) ? $value['entity'] : null,
            'entityId' => \is_string($value['entityId'] ?? null) && $value['entityId'] !== '' ? $value['entityId'] : null,
            'text' => \is_string($value['text'] ?? null) ? $value['text'] : '',
        ];
    }

    /**
     * Resolves a stored link value to a href. Returns an empty string when the link is
     * not configured, so templates can fall back themselves.
     */
    public function url(mixed $value): string
    {
        $link = self::normalize($value);

        if ($link['type'] === self::TYPE_EXTERNAL) {
            return $link['link'] ?? '';
        }

        if ($link['entityId'] === null) {
            return '';
        }

        return match ($link['entity']) {
            self::ENTITY_CATEGORY => $this->seoUrlReplacer->generate(
                'frontend.navigation.page',
                ['navigationId' => $link['entityId']]
            ),
            self::ENTITY_PRODUCT => $this->seoUrlReplacer->generate(
                'frontend.detail.page',
                ['productId' => $link['entityId']]
            ),
            default => '',
        };
    }
}
