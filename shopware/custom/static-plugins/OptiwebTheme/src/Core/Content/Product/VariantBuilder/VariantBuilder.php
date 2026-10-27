<?php declare(strict_types=1);

namespace OptiwebTheme\Core\Content\Product\VariantBuilder;

use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Turns existing, standalone products into variants of a parent — a new one, or
 * an existing parent. Shopware's admin can only generate variants from scratch.
 *
 * Every selected product keeps its id, number, price, stock and orders; it only
 * gets a parent and, as its variant options, the value it already has for each
 * chosen property (the value stays a property too). A product that cannot become
 * a variant is reported with the reason and left out:
 *
 * - it is a variant already, or a parent with variants of its own;
 * - it has no value, or more than one, for a chosen property;
 * - another product (or an existing variant of the parent) has the same values.
 *
 * preview() reports all of that without writing; create() re-runs the same checks
 * and writes the parent and the variants in one DAL write.
 */
class VariantBuilder
{
    public const ERROR_IS_VARIANT = 'isVariant';
    public const ERROR_IS_PARENT = 'isParent';
    public const ERROR_MISSING_PROPERTY = 'missingProperty';
    public const ERROR_MULTIPLE_VALUES = 'multipleValues';
    public const ERROR_DUPLICATE_COMBINATION = 'duplicateCombination';

    /** Fields a variant inherits from its parent when "inherit data" is on. */
    private const INHERITED_TRANSLATED_FIELDS = ['name', 'description', 'metaTitle', 'metaDescription', 'keywords'];

    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $propertyGroupRepository,
    ) {
    }

    /**
     * @param array<int, string> $productIds
     * @param array<int, string> $groupIds  ignored when adding to an existing parent (its groups are used)
     *
     * @return array<string, mixed>
     */
    public function preview(array $productIds, array $groupIds, ?string $parentId, Context $context): array
    {
        $errors = [];
        $parent = null;
        $existingCombinations = [];

        if ($parentId !== null) {
            $parent = $this->loadParent($parentId, $context);
            if ($parent === null) {
                $errors[] = ['code' => 'parentNotFound'];
            } else {
                $groupIds = $this->parentGroupIds($parent);
                foreach ($parent->getChildren() ?? [] as $child) {
                    $existingCombinations[$this->combinationKey($child->getOptionIds() ?? [])] = (string) $child->getProductNumber();
                }
                if ($groupIds === []) {
                    $errors[] = ['code' => 'parentHasNoGroups'];
                }
            }
        }

        $groupIds = array_values(array_unique(array_filter($groupIds)));
        if ($groupIds === [] && $parentId === null) {
            $errors[] = ['code' => 'noGroups'];
        }

        $groups = $this->loadGroups($groupIds, $context);
        $products = $this->loadProducts(array_diff($productIds, $parentId !== null ? [$parentId] : []), $context);

        $rows = [];
        $seen = $existingCombinations;
        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            if ($product === null) {
                continue;
            }

            $row = $this->row($product, $groupIds, $groups);

            if ($row['errors'] === []) {
                $key = $this->combinationKey(array_column($row['options'], 'optionId'));
                if (isset($seen[$key])) {
                    $row['errors'][] = ['code' => self::ERROR_DUPLICATE_COMBINATION, 'productNumber' => $seen[$key]];
                } else {
                    $seen[$key] = (string) $product->getProductNumber();
                }
            }

            $row['valid'] = $row['errors'] === [];
            $rows[] = $row;
        }

        $valid = array_values(array_filter($rows, static fn (array $row): bool => $row['valid']));
        $minimum = $parentId !== null ? 1 : 2;
        if (\count($valid) < $minimum && $errors === []) {
            $errors[] = ['code' => 'notEnoughProducts', 'minimum' => $minimum];
        }

        return [
            'groups'          => array_values(array_map(static fn ($group) => [
                'id'   => $group->getId(),
                'name' => (string) ($group->getTranslation('name') ?? $group->getName()),
            ], $groups->getElements())),
            'rows'            => $rows,
            'validCount'      => \count($valid),
            'errors'          => $errors,
            'parent'          => $parent !== null ? [
                'id'            => $parent->getId(),
                'productNumber' => $parent->getProductNumber(),
                'name'          => $parent->getTranslation('name') ?? $parent->getName(),
            ] : null,
            'suggestedNumber' => $parentId === null && $valid !== [] ? $this->suggestNumber(array_column($valid, 'productNumber'), $context) : null,
            'suggestedName'   => $parentId === null && $valid !== [] ? $this->suggestName(array_column($valid, 'name')) : null,
        ];
    }

    /**
     * @param array<int, string> $productIds
     * @param array<int, string> $groupIds
     *
     * @return array{parentId: string, variantIds: array<int, string>, skipped: int}
     */
    public function create(
        array $productIds,
        array $groupIds,
        ?string $parentId,
        ?string $parentNumber,
        ?string $parentName,
        bool $inheritData,
        Context $context,
    ): array {
        $preview = $this->preview($productIds, $groupIds, $parentId, $context);
        if ($preview['errors'] !== []) {
            throw VariantBuilderException::invalid($preview['errors'][0]['code']);
        }

        $valid = array_values(array_filter($preview['rows'], static fn (array $row): bool => $row['valid']));
        $products = $this->loadProducts(array_column($valid, 'id'), $context);

        $writes = [];
        if ($parentId === null) {
            $parentNumber = trim((string) $parentNumber);
            $parentName = trim((string) $parentName);
            if ($parentNumber === '') {
                throw VariantBuilderException::invalid('parentNumberMissing');
            }
            if ($parentName === '') {
                throw VariantBuilderException::invalid('parentNameMissing');
            }
            if ($this->numberExists($parentNumber, $context)) {
                throw VariantBuilderException::invalid('parentNumberTaken');
            }

            $template = $products->get($valid[0]['id']);
            \assert($template instanceof ProductEntity);

            $parentId = Uuid::randomHex();
            $writes[] = $this->newParent(
                $parentId,
                $parentNumber,
                $parentName,
                $template,
                $preview['groups'],
                array_unique(array_merge(...array_map(static fn (array $row) => array_column($row['options'], 'optionId'), $valid))),
                $valid[0]['id'],
            );
        } else {
            $parent = $this->loadParent($parentId, $context);
            \assert($parent !== null);

            $known = [];
            foreach ($parent->getConfiguratorSettings() ?? [] as $setting) {
                $known[$setting->getOptionId()] = true;
            }

            $settings = [];
            foreach ($valid as $row) {
                foreach (array_column($row['options'], 'optionId') as $optionId) {
                    if (!isset($known[$optionId])) {
                        $known[$optionId] = true;
                        $settings[] = ['optionId' => $optionId];
                    }
                }
            }

            if ($settings !== []) {
                $writes[] = ['id' => $parentId, 'configuratorSettings' => $settings];
            }
        }

        foreach ($valid as $row) {
            $product = $products->get($row['id']);
            \assert($product instanceof ProductEntity);

            $variant = [
                'id'       => $product->getId(),
                'parentId' => $parentId,
                'options'  => array_map(static fn (array $option) => ['id' => $option['optionId']], $row['options']),
            ];

            if ($inheritData) {
                $variant['translations'] = $this->clearedTranslations($product);
            }

            $writes[] = $variant;
        }

        // One write: parent, configurator and every variant land together or not at all.
        $this->productRepository->upsert($writes, $context);

        return [
            'parentId'   => $parentId,
            'variantIds' => array_column($valid, 'id'),
            'skipped'    => \count($preview['rows']) - \count($valid),
        ];
    }

    /**
     * @param array<int, string> $groupIds
     *
     * @return array<string, mixed>
     */
    private function row(ProductEntity $product, array $groupIds, PropertyGroupCollection $groups): array
    {
        $row = [
            'id'            => $product->getId(),
            'productNumber' => (string) $product->getProductNumber(),
            'name'          => (string) ($product->getTranslation('name') ?? $product->getName()),
            'options'       => [],
            'errors'        => [],
        ];

        if ($product->getParentId() !== null) {
            $row['errors'][] = ['code' => self::ERROR_IS_VARIANT];

            return $row;
        }

        if ($product->getChildCount() > 0) {
            $row['errors'][] = ['code' => self::ERROR_IS_PARENT];

            return $row;
        }

        /** @var array<string, array<int, PropertyGroupOptionEntity>> $byGroup */
        $byGroup = [];
        foreach ($product->getProperties() ?? [] as $option) {
            $byGroup[$option->getGroupId()][] = $option;
        }

        foreach ($groupIds as $groupId) {
            $groupName = (string) ($groups->get($groupId)?->getTranslation('name') ?? $groups->get($groupId)?->getName() ?? '');
            $options = $byGroup[$groupId] ?? [];

            if ($options === []) {
                $row['errors'][] = ['code' => self::ERROR_MISSING_PROPERTY, 'group' => $groupName];

                continue;
            }

            if (\count($options) > 1) {
                $row['errors'][] = [
                    'code'   => self::ERROR_MULTIPLE_VALUES,
                    'group'  => $groupName,
                    'values' => implode(', ', array_map(static fn ($option) => (string) ($option->getTranslation('name') ?? $option->getName()), $options)),
                ];

                continue;
            }

            $row['options'][] = [
                'groupId'  => $groupId,
                'group'    => $groupName,
                'optionId' => $options[0]->getId(),
                'name'     => (string) ($options[0]->getTranslation('name') ?? $options[0]->getName()),
            ];
        }

        return $row;
    }

    /**
     * An empty parent: name and number from the dialog; tax, price and sales
     * channels from the first variant, because a parent cannot be saved — or
     * listed — without them. Everything else is left for the editor.
     *
     * @param array<int, array{id: string, name: string}> $groups
     * @param array<int, string> $optionIds
     *
     * @return array<string, mixed>
     */
    private function newParent(
        string $parentId,
        string $number,
        string $name,
        ProductEntity $template,
        array $groups,
        array $optionIds,
        string $mainVariantId,
    ): array {
        return [
            'id'            => $parentId,
            'productNumber' => $number,
            'name'          => $name,
            'active'        => true,
            'stock'         => 0,
            'taxId'         => $template->getTaxId(),
            'price'         => array_values(array_map(static fn (Price $price) => [
                'currencyId' => $price->getCurrencyId(),
                'gross'      => $price->getGross(),
                'net'        => $price->getNet(),
                'linked'     => $price->getLinked(),
            ], $template->getPrice()?->getElements() ?? [])),
            'visibilities'  => array_values(array_map(static fn (ProductVisibilityEntity $visibility) => [
                'salesChannelId' => $visibility->getSalesChannelId(),
                'visibility'     => $visibility->getVisibility(),
            ], $template->getVisibilities()?->getElements() ?? [])),
            'configuratorSettings' => array_values(array_map(static fn (string $optionId) => ['optionId' => $optionId], $optionIds)),
            // One product in the listing, showing the first variant.
            'variantListingConfig' => [
                'displayParent'           => false,
                'mainVariantId'           => $mainVariantId,
                'configuratorGroupConfig' => array_map(static fn (array $group) => [
                    'id'                    => $group['id'],
                    'representation'        => 'box',
                    'expressionForListings' => false,
                ], $groups),
            ],
        ];
    }

    /**
     * Name, description and SEO texts set to null in every language the variant
     * has them in, so they are inherited from the parent.
     *
     * @return array<string, array<string, null>>
     */
    private function clearedTranslations(ProductEntity $product): array
    {
        $translations = [];
        foreach ($product->getTranslations() ?? [] as $translation) {
            $translations[$translation->getLanguageId()] = array_fill_keys(self::INHERITED_TRANSLATED_FIELDS, null);
        }

        return $translations;
    }

    /**
     * @param array<int, string> $productIds
     */
    private function loadProducts(array $productIds, Context $context): ProductCollection
    {
        $productIds = array_values(array_filter($productIds, static fn ($id) => \is_string($id) && Uuid::isValid($id)));
        if ($productIds === []) {
            return new ProductCollection();
        }

        $criteria = new Criteria($productIds);
        $criteria->addAssociation('properties');
        $criteria->addAssociation('visibilities');
        $criteria->addAssociation('translations');

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $context)->getEntities();

        return $products;
    }

    private function loadParent(string $parentId, Context $context): ?ProductEntity
    {
        if (!Uuid::isValid($parentId)) {
            return null;
        }

        $criteria = new Criteria([$parentId]);
        $criteria->addAssociation('configuratorSettings.option');
        $criteria->addAssociation('children');

        $parent = $this->productRepository->search($criteria, $context)->first();
        if (!$parent instanceof ProductEntity || $parent->getParentId() !== null) {
            return null;
        }

        return $parent;
    }

    /**
     * @return array<int, string>
     */
    private function parentGroupIds(ProductEntity $parent): array
    {
        $groupIds = [];
        foreach ($parent->getConfiguratorSettings() ?? [] as $setting) {
            $groupId = $setting->getOption()?->getGroupId();
            if ($groupId !== null) {
                $groupIds[$groupId] = $groupId;
            }
        }

        return array_values($groupIds);
    }

    /**
     * @param array<int, string> $groupIds
     */
    private function loadGroups(array $groupIds, Context $context): PropertyGroupCollection
    {
        if ($groupIds === []) {
            return new PropertyGroupCollection();
        }

        /** @var PropertyGroupCollection $groups */
        $groups = $this->propertyGroupRepository->search(new Criteria($groupIds), $context)->getEntities();

        // Keep the order the groups were picked in.
        $sorted = new PropertyGroupCollection();
        foreach ($groupIds as $groupId) {
            if ($group = $groups->get($groupId)) {
                $sorted->add($group);
            }
        }

        return $sorted;
    }

    /**
     * @param array<int, string> $optionIds
     */
    private function combinationKey(array $optionIds): string
    {
        sort($optionIds);

        return implode('|', $optionIds);
    }

    /**
     * The numbers' common beginning, cut back to a separator so no number is cut
     * in half ("BABY HAPPY B.01", "BABY HAPPY B.04" → "BABY HAPPY B"); when that
     * leaves less than three characters ("300860", "300880" share only "3008"),
     * the first number with "-V". A number already in use gets "-2", "-3", …
     *
     * @param array<int, string> $numbers
     */
    private function suggestNumber(array $numbers, Context $context): string
    {
        $separators = " -_./";
        $prefix = $this->commonPrefix($numbers);

        $endsInsideAToken = false;
        foreach ($numbers as $number) {
            $next = mb_substr($number, mb_strlen($prefix), 1);
            if ($next !== '' && !str_contains($separators, $next) && !str_contains($separators, mb_substr($prefix, -1))) {
                $endsInsideAToken = true;
            }
        }
        if ($endsInsideAToken) {
            $cut = max(array_map(static fn (string $separator) => (int) mb_strrpos($prefix, $separator), str_split($separators)));
            $prefix = mb_substr($prefix, 0, $cut);
        }

        $prefix = rtrim($prefix, $separators);
        // A whole variant number is no parent number (one product, or one number
        // the start of all the others).
        $base = mb_strlen($prefix) >= 3 && !\in_array($prefix, $numbers, true) ? $prefix : $numbers[0] . '-V';

        $candidate = $base;
        for ($i = 2; $this->numberExists($candidate, $context); ++$i) {
            $candidate = $base . '-' . $i;
        }

        return $candidate;
    }

    /**
     * The words every name starts with ("ZADRGA SKRITA 22cm", "ZADRGA SKRITA 18cm"
     * → "ZADRGA SKRITA"); the first name when they share none.
     *
     * @param array<int, string> $names
     */
    private function suggestName(array $names): string
    {
        $words = null;
        foreach ($names as $name) {
            $current = preg_split('/\s+/u', trim($name)) ?: [];
            if ($words === null) {
                $words = $current;

                continue;
            }

            $common = [];
            foreach ($words as $i => $word) {
                if (($current[$i] ?? null) !== $word) {
                    break;
                }
                $common[] = $word;
            }
            $words = $common;
        }

        $name = rtrim(implode(' ', $words ?? []), " -_,./");

        return $name !== '' ? $name : $names[0];
    }

    /**
     * @param array<int, string> $values
     */
    private function commonPrefix(array $values): string
    {
        $prefix = (string) array_shift($values);
        foreach ($values as $value) {
            while ($prefix !== '' && !str_starts_with($value, $prefix)) {
                $prefix = mb_substr($prefix, 0, -1);
            }
        }

        return $prefix;
    }

    private function numberExists(string $number, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productNumber', $number));
        $criteria->setLimit(1);

        return $this->productRepository->searchIds($criteria, $context)->getTotal() > 0;
    }
}
