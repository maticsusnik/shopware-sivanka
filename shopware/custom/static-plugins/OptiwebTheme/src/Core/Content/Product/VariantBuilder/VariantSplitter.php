<?php declare(strict_types=1);

namespace OptiwebTheme\Core\Content\Product\VariantBuilder;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The way back from VariantBuilder: every variant of the chosen parents becomes a
 * standalone product again, and the parent is deleted (optional).
 *
 * Shopware has no such action, and deleting a parent deletes its variants, so
 * each variant first gets its own copy of whatever it inherited — name (parent
 * name + option values when it had none), description, tax, price, sales
 * channels, categories, manufacturer and images — then loses its parent and its
 * variant options. Properties stay as they are.
 */
class VariantSplitter
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $productOptionRepository,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param array<int, string> $parentIds
     *
     * @return array{split: array<int, array{id: string, productNumber: string, variants: int}>, skipped: array<int, string>}
     */
    public function split(array $parentIds, bool $deleteParent, Context $context): array
    {
        $parents = $this->loadParents($parentIds, $context);

        $result = ['split' => [], 'skipped' => []];
        foreach ($parentIds as $parentId) {
            $parent = $parents->get($parentId);
            if ($parent === null || $parent->getParentId() !== null || ($parent->getChildren()?->count() ?? 0) === 0) {
                $result['skipped'][] = $parentId;

                continue;
            }

            // One parent at a time, all or nothing.
            $this->connection->transactional(function () use ($parent, $deleteParent, $context): void {
                $this->splitParent($parent, $deleteParent, $context);
            });

            $result['split'][] = [
                'id'            => $parent->getId(),
                'productNumber' => (string) $parent->getProductNumber(),
                'variants'      => $parent->getChildren()?->count() ?? 0,
            ];
        }

        return $result;
    }

    private function splitParent(ProductEntity $parent, bool $deleteParent, Context $context): void
    {
        $writes = [];
        $optionLinks = [];

        foreach ($parent->getChildren() ?? [] as $variant) {
            $writes[] = $this->standalone($variant, $parent);

            foreach ($variant->getOptionIds() ?? [] as $optionId) {
                $optionLinks[] = ['productId' => $variant->getId(), 'optionId' => $optionId];
            }
        }

        $this->productRepository->update($writes, $context);

        if ($optionLinks !== []) {
            $this->productOptionRepository->delete($optionLinks, $context);
        }

        if ($deleteParent) {
            $this->productRepository->delete([['id' => $parent->getId()]], $context);
        } else {
            // A parent without variants keeps no configurator. The DAL write after
            // the SQL re-indexes the product and clears its caches.
            $this->connection->executeStatement(
                'DELETE FROM product_configurator_setting WHERE product_id = :id',
                ['id' => Uuid::fromHexToBytes($parent->getId())],
            );
            $this->productRepository->update([[
                'id'                   => $parent->getId(),
                'variantListingConfig' => null,
            ]], $context);
        }
    }

    /**
     * The variant as a product of its own. Detaching needs the required fields in
     * the same write (the DAL validates it like a new product), so they are always
     * sent — the variant's own value, else the parent's.
     *
     * @return array<string, mixed>
     */
    private function standalone(ProductEntity $variant, ProductEntity $parent): array
    {
        $optionNames = [];
        foreach ($variant->getOptions() ?? [] as $option) {
            $optionNames[] = (string) ($option->getTranslation('name') ?? $option->getName());
        }

        $parentName = (string) ($parent->getTranslation('name') ?? $parent->getName());
        $name = $variant->getName() ?? trim($parentName . ' ' . implode(' ', $optionNames));

        $write = [
            'id'            => $variant->getId(),
            'parentId'      => null,
            'productNumber' => $variant->getProductNumber(),
            'name'          => $name,
            'taxId'         => $variant->getTaxId() ?? $parent->getTaxId(),
            'stock'         => $variant->getStock(),
            'price'         => $this->prices($variant, $parent),
        ];

        if ($variant->getDescription() === null && $parent->getDescription() !== null) {
            $write['description'] = $parent->getDescription();
        }

        if ($variant->getManufacturerId() === null && $parent->getManufacturerId() !== null) {
            $write['manufacturerId'] = $parent->getManufacturerId();
        }

        // Without its own sales channels or categories the product would vanish
        // from the shop the moment it stops inheriting them.
        if (($variant->getVisibilities()?->count() ?? 0) === 0) {
            $write['visibilities'] = array_values(array_map(static fn ($visibility) => [
                'salesChannelId' => $visibility->getSalesChannelId(),
                'visibility'     => $visibility->getVisibility(),
            ], $parent->getVisibilities()?->getElements() ?? []));
        }

        if (($variant->getCategories()?->count() ?? 0) === 0 && ($parent->getCategories()?->count() ?? 0) > 0) {
            $write['categories'] = array_values(array_map(static fn (string $id) => ['id' => $id], $parent->getCategories()->getIds()));
        }

        if (($variant->getMedia()?->count() ?? 0) === 0 && ($parent->getMedia()?->count() ?? 0) > 0) {
            $media = [];
            $coverId = null;
            foreach ($parent->getMedia() as $productMedia) {
                $id = Uuid::randomHex();
                $media[] = ['id' => $id, 'mediaId' => $productMedia->getMediaId(), 'position' => $productMedia->getPosition()];
                if ($productMedia->getId() === $parent->getCoverId()) {
                    $coverId = $id;
                }
            }
            $write['media'] = $media;
            $write['coverId'] = $coverId ?? $media[0]['id'];
        }

        return $write;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function prices(ProductEntity $variant, ProductEntity $parent): array
    {
        $prices = $variant->getPrice() ?? $parent->getPrice();

        return array_values(array_map(static fn (Price $price) => [
            'currencyId' => $price->getCurrencyId(),
            'gross'      => $price->getGross(),
            'net'        => $price->getNet(),
            'linked'     => $price->getLinked(),
        ], $prices?->getElements() ?? []));
    }

    /**
     * @param array<int, string> $parentIds
     */
    private function loadParents(array $parentIds, Context $context): ProductCollection
    {
        $parentIds = array_values(array_filter($parentIds, static fn ($id) => \is_string($id) && Uuid::isValid($id)));
        if ($parentIds === []) {
            return new ProductCollection();
        }

        $criteria = new Criteria($parentIds);
        foreach (['visibilities', 'categories', 'media'] as $association) {
            $criteria->addAssociation($association);
            $criteria->addAssociation('children.' . $association);
        }
        $criteria->addAssociation('children.options');

        /** @var ProductCollection $parents */
        $parents = $this->productRepository->search($criteria, $context)->getEntities();

        return $parents;
    }
}
