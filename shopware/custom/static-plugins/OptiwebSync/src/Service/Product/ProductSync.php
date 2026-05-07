<?php declare(strict_types=1);

namespace OptiwebSync\Service\Product;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use OptiwebSync\Helper\ApiHelper;
use OptiwebSync\Helper\EnvHelper;
use OptiwebSync\Helper\GlobalVariables;
use OptiwebSync\Helper\OwLogger;
use OptiwebSync\Helper\ShopwareApiHelper;
use OptiwebSync\Service\SyncBase\AbstractSyncBase;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Throwable;

class ProductSync extends AbstractSyncBase
{
    private string $salesChannelId;
    private string $tax;
    private string $currencyId;
    private array $productHashMap;
    private array $unitMap;
    private array $productMap;
    private array $productVisibilityMap;
    private array $productMapByNumber;
    private array $propertyMap;
    private array $categoryMap;
    private array $manufacturerMap;
    private array $requiredFields = [
        'id',
        'productNumber',
        'name',
    ];

    private ProductPropertyHandler $propertyHandler;
    private ProductMediaHandler $mediaHandler;
    private ?ProductVariationHandler $variationHandler;

    public function __construct(
        SystemConfigService $systemConfigService,
        ShopwareApiHelper $shopwareApiHelper,
        Connection $connection,
        ApiHelper $apiHelper,
        ProductPropertyHandler $propertyHandler,
        ProductMediaHandler $mediaHandler,
        ProductVariationHandler $variationHandler = null
    ) {
        parent::__construct($systemConfigService, $shopwareApiHelper, $connection, $apiHelper);
        $this->propertyHandler = $propertyHandler;
        $this->mediaHandler = $mediaHandler;
        $this->variationHandler = $variationHandler;
    }

    public function initialize(OwLogger $logger = null): void
    {
        $this->logger = $logger ?? OwLogger::generate("ProductImportSync", "product-import-sync", true);

        $this->salesChannelId = $this->shopwareApiHelper->getDefaultSalesChannelId();
        $this->tax = $this->shopwareApiHelper->getDefaultTaxId();
        $this->currencyId = $this->shopwareApiHelper->getDefaultCurrencyId();
        $this->productHashMap = $this->getProductHashMap();
        $this->productMap = $this->getProductMapV2();
        $this->productMapByNumber = $this->getProductMapByNumber();
        $this->productVisibilityMap = $this->getProductVisibilityMap();
        $this->propertyMap = [];
        $this->unitMap = $this->getunitMapV2();
        $this->categoryMap = $this->getCategoryMapV2();
        $this->manufacturerMap = $this->getManufacturerMap();

        if ($this->variationHandler) {
            $this->variationHandler->initialize($this->logger, $this->productMapByNumber, $this->productHashMap, $this->salesChannelId, $this->currencyId);
        }
    }

    function addApiParameters(array $params, array $loopData): array
    {
        if(!empty($this->setId)) {
            $params['sku'] = $this->setId;
        }
        return $params;
    }

    protected function import(array $dataArray, array $loopData): int
    {
        $countInserted = 0;
        $bulkPayload = [];
        $variationResults = [];
        $this->propertyHandler->clearPropertyBulkPayloads();
        $this->mediaHandler->clearMediaBulkPayload();
        $this->variationHandler->clearParentBulkPayload();

        if($this->ignoreHash){
            $this->mediaHandler->setIgnoreHash($this->ignoreHash);
        }

        foreach ($dataArray as $data) {
            $properties = [];
            $importData = [];
            try {

                foreach ($this->requiredFields as $field) {
                    if (empty($data[$field])) {
                        $identifier = $data['id'] ?? $data['number'] ?? 'unknown';
                        throw new \Exception("Required field '$field' is empty for product: $identifier");
                    }
                }

                $dataHash = @hash('sha256', json_encode($data));
                $existingHash = $this->productHashMap[$data['productNumber']] ?? '';
                if($dataHash == $existingHash && !$this->ignoreHash) {
                    continue;
                }

                $newEntry = true;
                $id = str_replace('-', '', $data['id']);
                $productNumber = $data['productNumber'];

                if (array_key_exists($id, $this->productMap)) {
                    $newEntry = false;
                }

                $importData = [
                    'id' => $id,
                    'productNumber' => $productNumber,
                    'taxId' => $this->tax,
                ];
                $importData['translations']['sl-SI'] = [];

                $visibility = 30; //VISIBILITY_ALL
                $isActive = $data['active'] === true;

                if(!$isActive) {
                    $visibility = 10; // VISIBILITY_LINK
                }

                $visibilities = [
                    'salesChannelId' => $this->salesChannelId,
                    'visibility' => $visibility,
                ];

                if ($newEntry) {
                    $importData['visibilities'] = [$visibilities];
                }else {
                    $existingVisibilityData = $this->productVisibilityMap[$id][$this->salesChannelId] ?? null;
                    if($existingVisibilityData) {
                        $existingVisibility = $existingVisibilityData['visibility'];
                        if($existingVisibility != $visibility) {
                            $visibilities['id'] = $existingVisibilityData['id'];
                            $importData['visibilities'] = [$visibilities];
                        }
                    }
                }

                $importData['isCloseout'] = true;

                $importData['active'] = true;

                $importData['translations']['sl-SI']['name'] = trim($data['name']);

                $importData['stock'] = $this->productMap[$id]["stock"] ?? 0;

                if ($newEntry) {
                    $price = isset($data['price'][0]) && floatval($data['price'][0]) > 0 ? floatval($data['price'][0]) : floatval(0);
                    $swPrice = $this->convertToShopwarePrice($price);
                    $importData['price'] = $swPrice;
                }else {
                    $existingPrice = $this->productMap[$id]["price"] ?? [];
                    $price = $existingPrice ?: $this->convertToShopwarePrice(0);
                    $importData['price'] = $price;
                }
                $translations = $data['translated'] ?? [];

                if (!empty($translations) && is_array($translations)) {

                    if (!empty($translations['name']['sl'])) {
                        $importData['translations']['sl-SI']['name'] = (string) $translations['name']['sl'];
                    }

                    if (!empty($translations['description']['sl'])) {
                        $importData['translations']['sl-SI']['description'] = (string) $translations['description']['sl'];
                    }

                    if (!empty($translations['metaTitle']['sl'])) {
                        $importData['translations']['sl-SI']['metaTitle'] = mb_substr($translations['metaTitle']['sl'], 0, 50);
                    }

                    if (!empty($translations['metaDescription']['sl'])) {
                        $importData['translations']['sl-SI']['metaDescription'] = mb_substr(strip_tags($translations['metaDescription']['sl']), 0, 150) ?? "";
                    }

                }

                $importData['purchaseSteps'] = 1;
                $importData['minPurchase'] = 1;

                $dataCustomFields = $data['customFields'] ?? [];
                if($dataCustomFields) {
                    if(isset($dataCustomFields['unit'])){
                        $importData['unitId'] = $this->unitMap[$dataCustomFields['unit']]['id'] ?? null;
                        $importData['packUnit'] = $dataCustomFields['unit'];
                    }
                    if(isset($dataCustomFields['unit2'])){
                        $importData['translations']['sl-SI']['customFields']['opucUnit'] = $dataCustomFields['unit2'];
                    }
                    if(isset($dataCustomFields['unitConverter'])){
                        $importData['translations']['sl-SI']['customFields']['opucConverter'] = $dataCustomFields['unitConverter'];

                        $minBuyQuantity = $dataCustomFields['unitConverter'];
                        if($minBuyQuantity){
                            $importData['purchaseSteps'] = intval(floatval($minBuyQuantity) * 10000);
                            $importData['minPurchase'] = intval(floatval($minBuyQuantity) * 10000);
                        }

                    }

                    //set manufacturer if brand is set and exists in map
                    if(!empty($dataCustomFields['brand'])){
                        $manufacturerId = $this->manufacturerMap[$dataCustomFields['brand']] ?? null;
                        $importData['manufacturerId'] = $manufacturerId;
                    }

                    //icons - goes to customFields.icons (array of icon IDs)
                    if(isset($dataCustomFields['icons']) && is_array($dataCustomFields['icons']) && !$this->ignoreMedia) {
                        if (!empty($dataCustomFields['icons'])) {
                            // Extract URLs from the icons array
                            $iconUrls = array_column($dataCustomFields['icons'], 'url');
                            $iconNames = array_column($dataCustomFields['icons'], 'name');

                            // Set to null if all names are empty (let processMedia handle empty individual names)
                            $iconNames = array_filter($iconNames, fn($name) => !empty($name)) ? $iconNames : null;

                            // Process icons and get media IDs to store in customFields
                            $iconResult = $this->mediaHandler->processMedia(null, $iconUrls, $iconNames);
                            $iconIds = [];
                            if (is_string($iconResult)) {
                                $iconIds[] = $iconResult;
                            } elseif (is_array($iconResult)) {
                                foreach ($iconResult as $iconId) {
                                    if (is_string($iconId)) {
                                        $iconIds[] = $iconId;
                                    }
                                }
                            }
                            if (!empty($iconIds)) {
                                $importData['translations']['sl-SI']['customFields']['icons'] = $iconIds;
                            }
                        } else {
                            $importData['translations']['sl-SI']['customFields']['icons'] = [];
                        }
                    }

                    if(isset($dataCustomFields['variantsParentTitle'])){
                        $importData['translations']['sl-SI']['customFields']['variantsParentTitle'] = $dataCustomFields['variantsParentTitle'];
                    }

                    if(isset($dataCustomFields['variants'])){
                        $listingDisplayProperties = [];
                        foreach ($dataCustomFields['variants'] as $variant) {
                            if (!empty($variant['title']) && $variant['listingDisplay']) {
                                $listingDisplayProperties[] = ProductPropertyHandler::canonicalizeKey($variant['title']);
                            }
                        }
                        $importData['translations']['sl-SI']['customFields']['listingDisplayProperties'] = $listingDisplayProperties;
                    }

                    if(isset($dataCustomFields['VariantPropertiesAdditional'])){
                        $variantPropertiesAdditional = [];
                        foreach ($dataCustomFields['VariantPropertiesAdditional'] as $variant) {
                            if (!empty($variant['title'])) {
                                $variantPropertiesAdditional[] = ProductPropertyHandler::canonicalizeKey($variant['title']);
                            }
                        }
                        $importData['translations']['sl-SI']['customFields']['variantPropertiesAdditional'] = $variantPropertiesAdditional;
                    }

                    if(isset($dataCustomFields['relatedProducts'])){
                        $importData['translations']['sl-SI']['customFields']['relatedProducts'] = $dataCustomFields['relatedProducts'];
                    }

                    if(isset($dataCustomFields['documents']) && is_array($dataCustomFields['documents'])){
                        $baseUrl = EnvHelper::read("PIM_SYNC_IMAGE_URL");
                        $documents = [];
                        foreach ($dataCustomFields['documents'] as $document) {
                            // Skip if document is empty or url is not set
                            if (empty($document['url'])) {
                                continue;
                            }
                            $documents[] = [
                                'name' => $document['name'] ?? '',
                                'url' => $baseUrl . $document['url']
                            ];
                        }
                        $importData['translations']['sl-SI']['customFields']['documents'] = $documents;
                    }

                    if(isset($dataCustomFields['videoLinks'])){
                        $importData['translations']['sl-SI']['customFields']['videoLinks'] = $dataCustomFields['videoLinks'];
                    }

                    if(isset($dataCustomFields['orderIdent'])){
                        $importData['translations']['sl-SI']['customFields']['orderIdent'] = $dataCustomFields['orderIdent'];
                    }
                }

                if(isset($data['weight']) && floatval($data['weight']) > 0) { $importData['weight'] = $data['weight']; }
                if(isset($data['height']) && floatval($data['height']) > 0) { $importData['height'] = $data['height']; }
                if(isset($data['length']) && floatval($data['length']) > 0) { $importData['length'] = $data['length']; }
                if(isset($data['width']) && floatval($data['width']) > 0) { $importData['width'] = $data['width']; }

                //categories
                if($isActive){
                    $category = $data['parent'] ?? '';
                    $importData['categories'] = $this->handleCategories($category, $id);
                }else {
                    $importData['categories'] = $this->handleCategories('', $id);
                }

                //properties
                if(!empty($data['properties']) && is_array($data['properties'])) {
                    $properties = $this->propertyHandler->processProperties($id, $data['properties'], $this->productMap[$id]['propertyIds'] ?? [], $this->productMap[$id]['optionIds'] ?? []);
                    if(!empty($properties['properties'])) {
                        $importData['properties'] = $properties['properties'];
                    }
                    if(!empty($properties['propertyMap'])) {
                        $this->propertyMap = $properties['propertyMap'];
                    }
                }

                if (!$this->ignoreMedia) {
                    $media = [];
                    $videos = [];

                    if (!empty($dataCustomFields['videos']) && is_array($dataCustomFields['videos'])) {
                        $videos = $this->mediaHandler->processMedia($id, $dataCustomFields['videos']);
                        if (!is_array($videos)) {
                            $videos = [];
                        }
                    }

                    if (!empty($data['media']) && is_array($data['media'])) {
                        $media = $this->mediaHandler->processMedia($id, $data['media']);
                        if (!is_array($media)) {
                            $media = [];
                        }
                        if (!empty($media)) {
                            $importData['coverId'] = $media[0]['id'];
                        }
                    } elseif (array_key_exists('media', $data)) {
                        $importData['coverId'] = null;
                    }

                    $mergedMedia = array_merge($media, $videos);
                    if (!empty($mergedMedia)) {
                        $importData['media'] = $mergedMedia;
                    }
                }

                //variations
                $variantsParent = $dataCustomFields['variantsParent'] ?? null;
                $existingProduct = $this->productMapByNumber[$productNumber] ?? null;
                if ($existingProduct && $existingProduct['parentId'] && !$variantsParent && $this->variationHandler) {
                    // Product was previously a variant but now has no variantsParent, detach all variant relations
                    $importData['parentId'] = null;
                    //TODO add parent product cleanup in product_option and product_configurator_settings
                } else if ($this->variationHandler) {

                    $productDataForVariations = [
                        'id' => $id,
                        'productNumber' => $productNumber,
                        'name' => $data['name'] ?? '',
                        'customFields' => $dataCustomFields,
                        'images' => $data['media']
                    ];

                    $childMediaId = '';
                    if (!empty($media) && isset($media[0]['media']['id'])) {
                        $childMediaId = $media[0]['media']['id'];
                    }

                    $variationResult = $this->variationHandler->processVariations(
                        $productDataForVariations,
                        $this->tax,
                        $this->propertyMap,
                        $childMediaId,
                        $this->ignoreHash,
                        $this->ignoreMedia
                    );

                    if (!empty($variationResult['options'])) {
                        $importData['options'] = $variationResult['options'];
                    }

                    if (!empty($variationResult['parentId'])) {
                        $importData['parentId'] = $variationResult['parentId'];
                        $variationResults[] = $variationResult;
                    }
                }

                //optiwebQuickOrderOnly
                $importData['translations']['sl-SI']['customFields']['optiwebQuickOrderOnly'] = !$isActive;
                //hash
                $importData['translations']['sl-SI']['customFields']['dataHash'] = $dataHash;

                $bulkPayload[] = $importData;
                $countInserted++;

            } catch (Throwable $error) {
                OwLogger::addVisibleLog($this->logger, 'Error importing SKU: ' . $data['productNumber'] . ' - ' . $error->getMessage() . ' (' . $error->getFile() . ' Line: ' . $error->getLine() . ')');
            }
        }

        // Flush dependency bulks in order: groups -> options -> media -> parents -> children
        if ($this->propertyHandler) {
            $propertyGroups = $this->propertyHandler->getPropertyGroupBulkPayload();
            if (!empty($propertyGroups)) {
                $this->shopwareApiHelper->bulkAddData(
                    uniqid('property-group-bulk-', true),
                    'property_group',
                    'upsert',
                    $propertyGroups
                );
            }
            
            $propertyOptions = $this->propertyHandler->getPropertyOptionBulkPayload();
            if (!empty($propertyOptions)) {
                $this->shopwareApiHelper->bulkAddData(
                    uniqid('property-option-bulk-', true),
                    'property_group_option',
                    'upsert',
                    $propertyOptions
                );
            }
        }

        // 3. Media entities (referenced by products)
        if ($this->mediaHandler) {
            $mediaPayload = $this->mediaHandler->getMediaBulkPayload();
            if (!empty($mediaPayload)) {
                $this->shopwareApiHelper->bulkAddData(
                    uniqid('media-bulk-', true),
                    'media',
                    'upsert',
                    $mediaPayload
                );
            }
        }

        // 4. Parent products (must exist before child products reference them)
        if ($this->variationHandler) {
            $parentProducts = $this->variationHandler->getParentBulkPayload();
            if (!empty($parentProducts)) {
                $this->shopwareApiHelper->bulkAddData(
                    uniqid('parent-product-bulk-', true),
                    'product',
                    'upsert',
                    $parentProducts
                );
            }
        }

        // Flush all dependency bulks together
        $this->shopwareApiHelper->bulkDataProcessQueue();

        // Process pending media uploads (media entities must exist first)
        if ($this->mediaHandler) {
            $this->mediaHandler->processPendingMediaUploads();
        }

        // 5. Main products (children + standalone products)
        if (!empty($bulkPayload)) {
            $this->shopwareApiHelper->bulkAddData(
                uniqid('product-upsert-', true),
                'product',
                'upsert',
                $bulkPayload
            );
            $this->shopwareApiHelper->bulkDataProcessQueue();
        }

        // Process variant relationships after products are created
        if (!empty($variationResults) && $this->variationHandler) {
            $this->variationHandler->processVariantRelationships($variationResults);
        }

        // Post-pass: assign cover images to parents that don't have one
        if ($this->variationHandler && !$this->ignoreMedia) {
            $this->assignMissingParentCovers($bulkPayload);
        }

        return $countInserted;
    }

    private function assignMissingParentCovers(array $childPayloads): void
    {
        $parentsMissingCover = $this->variationHandler->getParentsWithoutCover();
        if (empty($parentsMissingCover)) {
            return;
        }

        $parentMediaIds = [];
        foreach ($childPayloads as $child) {
            $parentId = $child['parentId'] ?? null;
            if (!$parentId || !isset($parentsMissingCover[$parentId]) || isset($parentMediaIds[$parentId])) {
                continue;
            }
            $firstMedia = $child['media'][0] ?? null;
            if ($firstMedia && isset($firstMedia['media']['id'])) {
                $parentMediaIds[$parentId] = $firstMedia['media']['id'];
            }
        }

        if (empty($parentMediaIds)) {
            return;
        }

        $updatePayload = [];
        foreach ($parentMediaIds as $parentId => $mediaId) {
            $productMediaId = Uuid::randomHex();
            $updatePayload[] = [
                'id' => $parentId,
                'coverId' => $productMediaId,
                'media' => [
                    [
                        'id' => $productMediaId,
                        'media' => ['id' => $mediaId],
                    ],
                ],
            ];
        }

        $this->shopwareApiHelper->bulkAddData(
            uniqid('parent-cover-fix-', true),
            'product',
            'upsert',
            $updatePayload
        );
        $this->shopwareApiHelper->bulkDataProcessQueue();
    }

    private function handleCategories(string $data, string $productId): array
    {
        $returnValues = [];
        $data = explode(',', $data);
        $productCategories = [];
        foreach ($data as $value) {
            if (empty($value) && $value != 0) {
                continue;
            }

            $value = str_replace('-', '', $value);

            if (key_exists($value, $this->categoryMap)) {
                $returnValues[] = ["id" => $value];
                $productCategories[] = $value;
            }
        }

        //unset categories
        $existingProductCategories = $this->productMap[$productId]['categoryIds'] ?? [];
        if($productId && $existingProductCategories ){
            $categoriesToDelete = array_diff($existingProductCategories, $productCategories);
            $bulkPayload = [];
            foreach ($categoriesToDelete as $categoryId) {
                $bulkPayload[] = [
                    'productId' => $productId,
                    'categoryId' => $categoryId,
                ];
            }
            if($bulkPayload){
                $this->shopwareApiHelper->bulkAddData(uniqid('product-category-delete-' . $productId . '-', true), 'product_category', 'delete', $bulkPayload);
            }
        }

        return $returnValues;
    }

    /**
     * @throws Exception
     */
    private function getProductMapV2(): array
    {
        $sql = <<<SQL
            SELECT
                LOWER(HEX(id)) AS id,
                product_number,
                stock,
                JSON_UNQUOTE(JSON_EXTRACT(property_ids, '$')) AS propertyIds,
                JSON_UNQUOTE(JSON_EXTRACT(option_ids, '$')) AS optionIds,
                JSON_UNQUOTE(JSON_EXTRACT(category_ids, '$')) AS categoryIds,
                LOWER(HEX(parent_id)) AS parentId
            FROM product
            WHERE product_number IS NOT NULL AND product_number != ''
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql);
        $map = [];

        foreach ($rows as $row) {
            $map[$row['id']] = [
                'id' => $row['id'],
                'product_number' => $row['product_number'],
                'stock' => (int) $row['stock'],
                'propertyIds' => $row['propertyIds'] ? json_decode($row['propertyIds'], true) : [],
                'optionIds' => $row['optionIds'] ? json_decode($row['optionIds'], true) : [],
                'categoryIds' => $row['categoryIds'] ? json_decode($row['categoryIds'], true) : [],
                'parentId' => $row['parentId'] ?? null,
            ];
        }

        return $map;
    }

    /**
     * Get product map by product number
     *
     * @throws Exception
     */
    private function getProductMapByNumber(): array
    {
        $sql = <<<SQL
            SELECT
                LOWER(HEX(id)) AS id,
                product_number,
                LOWER(HEX(parent_id)) AS parentId,
                JSON_UNQUOTE(JSON_EXTRACT(option_ids, '$')) AS optionIds
            FROM product
            WHERE product_number IS NOT NULL AND product_number != ''
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql);
        $map = [];

        foreach ($rows as $row) {
            $map[$row['product_number']] = [
                'id' => $row['id'],
                'product_number' => $row['product_number'],
                'parentId' => $row['parentId'] ?? null,
                'optionIds' => $row['optionIds'] ? json_decode($row['optionIds'], true) : [],
            ];
        }

        return $map;
    }

    /**
     * Get product visibility map by product number
     *
     * @throws Exception
     */
    private function getProductVisibilityMap(): array
    {
        $sql = <<<SQL
            SELECT
                LOWER(HEX(id)) AS id,
                LOWER(HEX(product_id)) AS productId,
                visibility,
                LOWER(HEX(sales_channel_id)) AS salesChannelId
            FROM product_visibility
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql);
        $map = [];

        foreach ($rows as $row) {
            $map[$row['productId']][$row['salesChannelId']] = [
                'id' => $row['id'],
                'visibility' => $row['visibility']
            ];
        }

        return $map;
    }

    /**
     * Get property map V2 (reused from ProductPropertyHandler pattern)
     *
     * @throws Exception
     */
    private function getPropertyMapV2(): array
    {
        $propertyGroupRows = $this->connection->fetchAllAssociative("
            SELECT
                LOWER(HEX(pg.id)) AS id,
                LOWER(JSON_UNQUOTE(JSON_EXTRACT(pgt.custom_fields, '$.owSyncId'))) AS owSyncId
            FROM property_group pg
            LEFT JOIN property_group_translation pgt ON pg.id = pgt.property_group_id
            WHERE JSON_EXTRACT(pgt.custom_fields, '$.owSyncId') IS NOT NULL
        ");

        $propertyGroups = [];
        foreach ($propertyGroupRows as $row) {
            $propertyGroups[$row['id']] = $row['owSyncId'];
        }

        $propertyOptionRows = $this->connection->fetchAllAssociative("
            SELECT
                LOWER(HEX(pgo.id)) AS id,
                LOWER(HEX(pgo.property_group_id)) AS groupId,
                JSON_UNQUOTE(JSON_EXTRACT(pgot.custom_fields, '$.owSyncId')) AS owSyncId,
                JSON_UNQUOTE(JSON_EXTRACT(pgot.custom_fields, '$.syncValue')) AS syncValue
            FROM property_group_option pgo
            LEFT JOIN property_group_option_translation pgot
                ON pgo.id = pgot.property_group_option_id
            WHERE pgo.property_group_id IS NOT NULL
        ");

        $propertyOptions = [];

        foreach ($propertyOptionRows as $row) {
            $groupId = $row['groupId'];
            $groupSyncId = $propertyGroups[$groupId] ?? null;

            if (!$groupSyncId) {
                continue;
            }

            $key = null;
            if (!empty($row['syncValue']) || $row['syncValue'] === "0") {
                $key = mb_strtolower($row['syncValue']);
            } elseif (!empty($row['owSyncId']) || $row['owSyncId'] === "0") {
                $key = mb_strtolower($row['owSyncId']);
            }

            if ($key || $key === "0") {
                $propertyOptions[$groupSyncId][$key] = $row['id'];
            }
        }

        $propertyGroupOptions = [];

        foreach ($propertyGroups as $groupId => $groupSyncId) {
            $propertyGroupOptions[$groupSyncId] = [
                'values' => $propertyOptions[$groupSyncId] ?? [],
                'propertyGroupId' => $groupId,
            ];
        }

        return $propertyGroupOptions;
    }

    private function convertToShopwarePrice(float $value): array
    {
        return [[
            'net' => $value,
            'gross' => $value * GlobalVariables::PRICE_GROSS_MULTIPLIER,
            'linked' => true,
            'currencyId' => $this->currencyId,
        ]];
    }

    /**
     * @throws Exception
     */
    private function getCategoryMapV2(): array
    {

        $rows = $this->connection->fetchAllAssociative("
            SELECT
                LOWER(HEX(c.id)) AS id,
                ct.custom_fields
            FROM category c
            LEFT JOIN category_translation ct ON c.id = ct.category_id
        ");

        $map = [];

        foreach ($rows as $row) {
            $id = $row['id'];
            $map[$id] = [
                'id' => $id,
            ];
        }

        return $map;
    }

    private function getProductHashMap(): array
    {
        $sql = <<<SQL
            SELECT
                p.product_number,
                JSON_UNQUOTE(JSON_EXTRACT(pt.custom_fields, '$.dataHash')) AS dataHash
            FROM product p
            INNER JOIN product_translation pt ON p.id = pt.product_id
            WHERE p.product_number IS NOT NULL
              AND p.product_number != ''
              AND JSON_EXTRACT(pt.custom_fields, '$.dataHash') IS NOT NULL
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql);

        $map = [];

        foreach ($rows as $row) {
            if (!empty($row['dataHash'])) {
                $map[$row['product_number']] = $row['dataHash'];
            }
        }

        return $map;
    }

    private function getUnitMapV2(): array
    {
        $rows = $this->connection->fetchAllAssociative("
            SELECT
                LOWER(HEX(u.id)) AS id,
                ut.name AS name,
                ut.short_code AS shortCode
            FROM unit u
            LEFT JOIN unit_translation ut ON u.id = ut.unit_id
        ");

        $map = [];

        foreach ($rows as $row) {
            $id = $row['id'];
            $shortCode = $row['shortCode'];
            $map[$shortCode] = [
                'id' => $id,
                'name' => $row['name'],
                'shortCode' => $shortCode,
            ];
        }

        return $map;
    }

    /**
     * Get manufacturer map: ident (brand) => manufacturerId
     *
     * @throws Exception
     */
    private function getManufacturerMap(): array
    {
        $sql = <<<SQL
            SELECT
                LOWER(HEX(pm.id)) AS id,
                JSON_UNQUOTE(JSON_EXTRACT(pmt.custom_fields, '$.owSyncId')) AS owSyncId
            FROM product_manufacturer pm
            LEFT JOIN product_manufacturer_translation pmt ON pm.id = pmt.product_manufacturer_id
            WHERE JSON_EXTRACT(pmt.custom_fields, '$.owSyncId') IS NOT NULL
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql);
        $map = [];

        foreach ($rows as $row) {
            $owSyncId = $row['owSyncId'];
            if (!empty($owSyncId)) {
                $map[$owSyncId] = $row['id'];
            }
        }

        return $map;
    }


    public function getName(): string
    {
        return 'Product';
    }

    public function getSyncEndpoint(): string
    {
        return 'product';
    }

    public function getSyncArrayKey(): string
    {
        return 'data';
    }

    public function getSyncType(): string
    {
        return 'import';
    }

    public function getSyncOrigin(): string
    {
        return 'pim';
    }

    public function getSyncCommandNames(): array
    {
        return ['product', 'products'];
    }

    protected function getLockTtl(): int
    {
        return 7200; // 2 hours in seconds
    }

}
