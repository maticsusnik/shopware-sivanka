<?php declare(strict_types=1);

namespace OptiwebTheme\Storefront\Twig;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Legal pages (privacy, terms) carry their real text on the CATEGORY that uses
 * the layout, as category slot overrides. The core links open the bare layout in
 * an ajax modal (/widgets/cms/{id}), which shows the layout's placeholder text
 * instead. This resolves the category that uses a given layout, so the storefront
 * can link to the real page.
 */
class LegalPageExtension extends AbstractExtension
{
    /** @var array<string, string|null> cmsPageId → categoryId */
    private array $cache = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sv_legal_page_category', $this->categoryForCmsPage(...)),
        ];
    }

    /** The active category that uses the given CMS layout, or null. */
    public function categoryForCmsPage(?string $cmsPageId): ?string
    {
        if ($cmsPageId === null || !Uuid::isValid($cmsPageId)) {
            return null;
        }

        if (\array_key_exists($cmsPageId, $this->cache)) {
            return $this->cache[$cmsPageId];
        }

        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM category
             WHERE cms_page_id = :cmsPageId AND version_id = :version AND active = 1
             ORDER BY created_at ASC LIMIT 1',
            [
                'cmsPageId' => Uuid::fromHexToBytes($cmsPageId),
                'version'   => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ],
        );

        return $this->cache[$cmsPageId] = \is_string($id) && $id !== '' ? $id : null;
    }
}
