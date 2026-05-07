<?php declare(strict_types=1);

namespace OptiwebSync\Service\Order;

use DateTime;
use OptiwebSync\Helper\EnvHelper;
use OptiwebSync\Helper\ApiHelper;
use OptiwebSync\Helper\GlobalVariables;
use OptiwebSync\Helper\OwLogger;
use OptiwebSync\Service\SyncBase\AbstractSyncBase;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;

class OrderExportSync extends AbstractSyncBase
{

    public function initialize(): void
    {
        $this->logger = OwLogger::generate("OrderExportSync", "order-export-sync", true);
    }
    protected function setExportData(): array
    {
        $orderExportState = $this->systemConfigService->get("OptiwebSync.config.orderExportState");
        $allowedTransactionStateIds = $this->systemConfigService->get("OptiwebSync.config.allowedOrderPaymentStateToExport");

        $data = $this->shopwareApiHelper->getShopwareEntries('order', ['id','orderNumber'], function (array $data): array {
            $map = [];
            foreach ($data as $item) {
                $map[$item['attributes']['orderNumber']] = $item['id'];
            }
            return $map;
        },
            [
                'type' => 'multi',
                'operator' => 'and',
                'queries' => [
                    ['type' => 'equalsAny', 'field' => 'customFields.optiwebOrderStatus', 'value' =>  [GlobalVariables::STATUS_WAITING, GlobalVariables::STATUS_ERROR, GlobalVariables::STATUS_PARTIALLY_SENT]],
                    ['type' => 'equals', 'field' => 'stateId', 'value' => $orderExportState],
                    ['type' => 'equalsAny', 'field' => 'transactions.stateId', 'value' => $allowedTransactionStateIds ],
                ]
            ]
        );

        return $data;
    }
    protected function export(array $dataArray, array $loopData): int
    {
        $countExported = 0;

        foreach ($dataArray as $id) {
            $orderData = $this->shopwareApiHelper->getShopwareEntryDetailedInformation('order/' . $id );

            $orderDataInfo = $this->getOrderDataInformation($orderData);
            $customerData = $this->prepareCustomerData($orderDataInfo);

            if(empty($customerData)) continue;

            $paymentMethodId = $this->preparePaymentData($orderDataInfo);
            if(empty($paymentMethodId)) continue;

            $shippingMethodId = $this->prepareShippingData($orderDataInfo);
            if(empty($shippingMethodId)) continue;

            $paymentAndShippingMethod = $this->handlePaymentAndShippingMapping($paymentMethodId, $shippingMethodId);

            $orderUpsertData = $this->prepareOrderData($orderData, $orderDataInfo, $customerData, $paymentAndShippingMethod);

            $dataString = json_encode($orderUpsertData);

            $apiUrl = EnvHelper::read("VASCO_URL", self::class) . $this->getSyncEndpoint();
            OwLogger::addVisibleLog($this->logger, "Send Entry: order: $id, data:" . $dataString);
            $response = $this->apiHelper->sendDataToApi($apiUrl, $dataString, 'vasco');

            $this->handleOrderResponse($id, $response);

            $countExported++;

        }

        return $countExported;
    }

    public function checkOrderPaymentMethod($transactions, string $paymentType): bool {
        if($paymentType == 'creditCard'){
            $creditCardPaymentId = $this->systemConfigService->get("OptiwebSync.config.creditCardPaymentId");
            foreach ($transactions as $transaction){
                if($creditCardPaymentId === $transaction['attributes']['paymentMethodId']) {
                    return true;
                }
            }
        }

        return false;
    }

    private function handleCreditCardPayment($transactions){
        if(count($transactions) == 1){
            $isOrderTranslationPaid = $this->checkIfCreditCardOrderTranslationStateIsPaid($transactions[0]);
            if(!$isOrderTranslationPaid){
                return false;
            }
            return $transactions[0]['attributes']['paymentMethodId'];
        }else {
            //if multiple transactions, credit card failed, and we need to find the one that is not failed
            $paymentStatusFailedId = $this->systemConfigService->get("OptiwebSync.config.orderPaymentStatusFailedId");
            foreach ($transactions as $transaction) {
                if ($transaction['attributes']['stateMachineStateId'] !== $paymentStatusFailedId) {
                    return $transaction['attributes']['paymentMethodId'];
                }
            }
        }

        return false;
    }

    private function checkIfCreditCardOrderTranslationStateIsPaid($translaction){
        $allowedCreditCardTransactionStateId = $this->systemConfigService->get("OptiwebSync.config.orderPaymentCreditCardStateToExport");
        $orderTransactionStateId = $translaction['attributes']['stateId'];

        if($allowedCreditCardTransactionStateId === $orderTransactionStateId) return true;

        return false;
    }

    private function isJson($string) {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    // Method to get and prepare order data
    private function getOrderDataInformation($orderData)
    {
        // Implement the logic to get the order data here…
        $relationships = $orderData['relationships'];
        $requestedRelationships = ['currency', 'orderCustomer', 'lineItems', 'stateMachineState', 'transactions','addresses','deliveries'];
        $additionalOrderData = $this->shopwareApiHelper->getRelationships($relationships, $requestedRelationships);
        return $additionalOrderData;
    }

    // Method to get and prepare customer data
    private function prepareCustomerData($orderDataInfo)
    {
        $customerDetails = [];
        $orderCustomer = $orderDataInfo['orderCustomer'][0]['attributes'];
        $customerData = $this->shopwareApiHelper->getShopwareEntryDetailedInformation('customer/' . $orderCustomer['customerId'] );
        $customerCustomFields = $customerData['attributes']['customFields'] ?? null;

        $customerDetails['partnerId'] = $customerCustomFields['vascoSifra'] ?? 0;
        $customerDetails['prodajalna'] = $customerCustomFields['vascoProdajalnaSifra'] ?? 0;

        return $customerDetails;
    }

    // Method to get and prepare payment data
    private function preparePaymentData($orderDataInfo)
    {
        $transactions = $orderDataInfo['transactions'];
        if(!is_array($transactions) || count($transactions) == 0) return null;
        $isPaymentCreditCard = $this->checkOrderPaymentMethod($transactions,'creditCard');
        if($isPaymentCreditCard){
            $payment = $this->handleCreditCardPayment($transactions);
        }else{
            $payment = $transactions[0]['attributes']['paymentMethodId'];
        }
        return $payment;
    }

    private function prepareShippingData($orderDataInfo)
    {
        $deliveries = $orderDataInfo['deliveries'];
        if(!is_array($deliveries) || count($deliveries) == 0) return null;
        $shippingMethodId = $deliveries[0]['attributes']['shippingMethodId'];
        return $shippingMethodId;
    }

    private function prepareOrderData($orderData, $orderDataInfo, $customerData, $paymentAndShippingMethod)
    {
        // Collect all additional information
        $orderAttributes = $orderData['attributes'];

        $deliveries = $orderDataInfo['deliveries'];

        $products = $this->prepareProductData($orderDataInfo);
        // $products = $this->addShippingCostAsLineItem($orderAttributes, $customerData['priceType'], $deliveries, $products);

        $billingAddressId = $orderAttributes['billingAddressId'] ?? null;
        $billingAddressVersion = $orderAttributes['billingAddressVersionId'] ?? null;
        $billingAddress = $this->prepareBillingAddressData($orderDataInfo, $billingAddressId, $billingAddressVersion);
        $shippingAddress = $this->prepareShippingAddressData($orderDataInfo);

        $customerComment = $orderAttributes['customerComment'] ?? "";

        $acNote = $billingAddress . "\n\n" . $shippingAddress . "\n\n" . "Customer comment: " . $customerComment;
        $ordersToUpsertData = [];

        //get year from $orderAttributes['orderDateTime'], current format is 2025-10-01T14:35:20.555+00:00
        $orderDate = $orderAttributes['orderDateTime']; // "2025-10-01T14:35:20.555+00:00"
        $date = new DateTime($orderDate);
        $year = $date->format('Y');

        $orderCustomFields = $orderAttributes['customFields'] ?? [];
        $warehouseID = $orderCustomFields['warehouseId'] ?? 1; // default
        $warehouseID = $warehouseID == 0 ? 2 : $warehouseID;

        $orderUpsertData = [
            'tipStevilcenja' => 0,
            'stevilka' => $orderAttributes['orderNumber'],
            'leto' => $year,
            'datum' => $orderDate,
            'partner' => $customerData['partnerId'],
            'prodajalna' => $customerData['prodajalna'],
            // 'komercialist' => $warehouseID == 1 ? 49 : 444444,
            'komercialist' => 100,
            'vhodniMenu' => $warehouseID,
            'skladisce' => $warehouseID,
            'rabat1' => 0,
            'nastaviKupceveCene' => true,
            'postavke' => $products,
        ];

        return $orderUpsertData;
    }

    private function addShippingCostAsLineItem($order, $priceType, $deliveries, $products){

        $shippingCosts = $order->shippingCosts ?? null;

        if($shippingCosts){

            $shippingMethodId = $deliveries[0]['attributes']['shippingMethodId'] ?? '';

            $shippingPostaSlo = $this->systemConfigService->get("OptiwebSync.config.shippingMethodPostaSlo");
            $shippingPostaBlazic = $this->systemConfigService->get("OptiwebSync.config.shippingMethodPostaBLazic");
            $shippingGLS = $this->systemConfigService->get("OptiwebSync.config.shippingMethodGLS");
            $shippingDPD = $this->systemConfigService->get("OptiwebSync.config.shippingMethodDPD");

            $shippingMethods = [
                $shippingPostaSlo => 'POŠTNINA PS',
                $shippingPostaBlazic => 'POŠTNINA',
                $shippingGLS => 'POŠTNINA GLS',
                $shippingDPD => 'POŠTNINA DPD',
            ];

            $shippingMethod = $shippingMethods[$shippingMethodId] ?? '';

            if($shippingMethod){
                $price = strval($shippingCosts->totalPrice);
                $number = count($products);
                if($price > 0) {
                    $products[] = [
                        "anNo" => $number + 1,
                        "acIdent" => $shippingMethod,
                        "anQty" => "1",
                        // "anPrice" => $price,
                        $priceType => $price,
                        "acName" => "Strošek pošiljanja"
                    ];
                }
            }

        }

        return $products;

    }

    private function prepareProductData($orderDataInfo)
    {
        $products = [];
        $lineItems = $orderDataInfo['lineItems'];

        foreach ($lineItems as $lineItem) {
            $product = $lineItem['attributes'];
            $type = $product['type'];
            if($type != LineItem::PRODUCT_LINE_ITEM_TYPE) continue;

            $payload = $product['payload'];
            $productNumber = $payload['productNumber'];
            $qty = $product['quantity'];
            
            // Get lineItem unitPrice (may be adjusted with multiplier)
            $lineItemPrice = (float)$product['unitPrice'];
            
            // Try to get prices from customFields (set by OrderLineItemPriceSubscriber)
            $customFields = $product['customFields'] ?? [];
            $originalPrice = $customFields['ow_original_price'] ?? null;
            $customFieldsPrice = $customFields['ow_price'] ?? null;
            $rabat = $customFields['ow_rabat'] ?? 0;

            // Handle unit product multiplier for comparison
            $isOpucUnitProduct = $product['payload']['opucUnitProduct']['opucUnitProduct'] ?? false;
            $adjustedLineItemPrice = $lineItemPrice;
            if($isOpucUnitProduct){
                $baseUnit = strtolower($product['payload']['opucUnitProduct']['baseUnit']) ?? false;
                $multiplier = in_array($baseUnit, ['m2', 'm', 'cent'], true) ? 10000 : 1;
                $qty = $qty / $multiplier;
                $adjustedLineItemPrice = $lineItemPrice * $multiplier;
            }

            // Use customFields price if available and matches lineItem price (within tolerance)
            if ($originalPrice !== null) {
                $customFieldsPriceFloat = $customFieldsPriceFloatToCompare = (float)$originalPrice;
                if($rabat > 0){
                    $customFieldsPriceFloatToCompare = $customFieldsPriceFloat * (1 - ($rabat / 100)) ;
                }
                if (abs($customFieldsPriceFloatToCompare - $adjustedLineItemPrice) < 0.01) {
                    $priceToUse = $customFieldsPriceFloat;
                } else {
                    if($rabat > 0){
                        $priceToUse = round($adjustedLineItemPrice / (1 - ($rabat / 100)),4);
                    } else {
                        $priceToUse = $adjustedLineItemPrice;
                    }
                }
            } else {
                if($rabat > 0){
                    $priceToUse = round($adjustedLineItemPrice / (1 - ($rabat / 100)),4);
                } else {
                    $priceToUse = $adjustedLineItemPrice;
                }
            }

            // Calculate price with VAT (22%)
            $prodajnaCena = round($priceToUse, 4);
            $prodajnaCenaZDdv = round($prodajnaCena * 1.22, 4);

            $products[] = [
                "sifra" => $productNumber,
                "kolicina" => strval($qty),
                'prodajnaCena' => strval($prodajnaCena),
                'prodajnaCenaZDdv' => strval($prodajnaCenaZDdv),
                'rabat1' => $rabat, // cenikZaKupca v kolikor je rabat vrnjen v klicu ga zpišite
                'stopnjaDdv' => 0, // 0 = 22%, 1=9,5%, 2=0,0%, 3=5%
            ];
        }
        return $products;
    }

    private function prepareBillingAddressData($orderDataInfo, $billingAddressId, $billingAddressVersion)
    {
        $billingAddressApiResponse = $this->shopwareApiHelper->getShopwareEntryDetailedInformation('order-address/' . $billingAddressId ) ?? null;
        $billingAddressData = $billingAddressApiResponse['attributes'] ?? null;
        if($billingAddressId && $billingAddressVersion && empty($billingAddressData)) {
            foreach ($orderDataInfo['addresses'] as $address) {
                if ($address['id'] === $billingAddressId && $address['attributes']['versionId'] === $billingAddressVersion) {
                    $billingAddressData = $address['attributes'];
                    break;
                }
            }
        }

        $billingCountryId = $billingAddressData['countryId'];
        $billingCountryData = $this->shopwareApiHelper->getShopwareEntryDetailedInformation('country/' . $billingCountryId );
        $billingCountry = $billingCountryData['attributes']['name'];

        $orderCustomer = $orderDataInfo['orderCustomer'];
        $customerEmail = $orderCustomer[0]['attributes']['email'] ?? '';
        $customerVats = $orderCustomer[0]['attributes']['vatIds'] ?? [];
        $customerVat = '';
        if(count($customerVats) > 0) {
            $customerVat = $customerVats[0] ?? '';
        }

        $billingAddress = "Billing address: \n"
            . $billingAddressData['firstName'] . " "
            . $billingAddressData['lastName'] . "\n"
            . $billingAddressData['street'] . "\n"
            . $billingAddressData['zipcode'] . " "
            . $billingAddressData['city'] . "\n"
            . $billingCountry . "\n"
            . $billingAddressData['phoneNumber'] . "\n"
            . $customerEmail . "\n"
            . $billingAddressData['company'] . "\n"
            . $billingAddressData['department'] . "\n"
            . $customerVat . "\n";
        return $billingAddress;
    }

    private function prepareShippingAddressData($orderDataInfo)
    {
        $shippingAddressData = $this->shopwareApiHelper->getSingleRelationship($orderDataInfo['deliveries'][0],'shippingOrderAddress');
        $shippingAddress = $shippingAddressData[0]['attributes'];
        $shippingCountryId = $shippingAddress['countryId'];
        $shippingCountryData = $this->shopwareApiHelper->getShopwareEntryDetailedInformation('country/' . $shippingCountryId );
        $shippingCountry = $shippingCountryData['attributes']['name'];

        $orderCustomer = $orderDataInfo['orderCustomer'];
        $customerEmail = $orderCustomer[0]['attributes']['email'] ?? '';

        $shippingAddress = "Shipping address: \n"
            . $shippingAddress['firstName'] . " "
            . $shippingAddress['lastName'] . "\n"
            . $shippingAddress['street'] . "\n"
            . $shippingAddress['zipcode'] . " "
            . $shippingAddress['city'] . "\n"
            . $shippingCountry . "\n"
            . $shippingAddress['phoneNumber'] . "\n"
            . $customerEmail . "\n"
            . $shippingAddress['company'] . "\n"
            . $shippingAddress['department'];
        return $shippingAddress;
    }

    private function handleOrderResponse($id, $response): void
    {
        if($response['status'] === 'ok'){
            if(($response['error'] == "" || $response['error'] == null) && $response['response'] !== ""){
                $VascoResponse = $response['response'];
                $orderUpsertData = [
                    'customFields' => [
                        GlobalVariables::CUSTOM_FIELD_OPTIWEB_STATUS => GlobalVariables::STATUS_SENT,
                        GlobalVariables::CUSTOM_FIELD_OPTIWEB_KEY => json_decode($VascoResponse),
                    ],
                ];
            }else {
                $orderUpsertData = [
                    'customFields' => [
                        GlobalVariables::CUSTOM_FIELD_OPTIWEB_ERROR => $response['error'],
                    ],
                ];
            }
        }else {
            $orderUpsertData = [
                'customFields' => [
                    GlobalVariables::CUSTOM_FIELD_OPTIWEB_STATUS => GlobalVariables::STATUS_ERROR,
                    GlobalVariables::CUSTOM_FIELD_OPTIWEB_ERROR => $response,
                ],
            ];
        }

        $this->shopwareApiHelper->upsertData('order/', $orderUpsertData, false, $id);
    }

    private function handlePaymentAndShippingMapping($paymentId, $shippingId): array
    {
        //payment
        $paymentInvoice = $this->systemConfigService->get("OptiwebSync.config.paymentMethodInvoice");
        $paymentCod = $this->systemConfigService->get("OptiwebSync.config.paymentMethodCod");
        $paymentDobavnica = $this->systemConfigService->get("OptiwebSync.config.paymentMethodDobavnica");
        $paymentStripe = $this->systemConfigService->get("OptiwebSync.config.paymentMethodStripe");
        $paymentPayapal = $this->systemConfigService->get("OptiwebSync.config.paymentMethodPayapal");
        $paymentPayInShop = $this->systemConfigService->get("OptiwebSync.config.paymentMethodPayInShop");

        //shipping
        $shippingPostaSlo = $this->systemConfigService->get("OptiwebSync.config.shippingMethodPostaSlo");
        $shippingGLS = $this->systemConfigService->get("OptiwebSync.config.shippingMethodGLS");
        $shippingDPD = $this->systemConfigService->get("OptiwebSync.config.shippingMethodDPD");
        $shippingPickupLJ = $this->systemConfigService->get("OptiwebSync.config.shippingMethodPickupLJ");
        $shippingPickupMB = $this->systemConfigService->get("OptiwebSync.config.shippingMethodPickupMB");
        $shippingPickupNM = $this->systemConfigService->get("OptiwebSync.config.shippingMethodPickupNM");
        $shippingPickupBrnik = $this->systemConfigService->get("OptiwebSync.config.shippingMethodPickupBrnik");
        $shippingRobomatBrnik = $this->systemConfigService->get("OptiwebSync.config.shippingMethodRobomatBrnik");
        $shippingRobomatLj = $this->systemConfigService->get("OptiwebSync.config.shippingMethodRobomatLJ");
        $shippingPOSTA = $this->systemConfigService->get("OptiwebSync.config.shippingMethodPosta");
        $shippingPodgodbenaDostava = $this->systemConfigService->get("OptiwebSync.config.shippingMethodPogodbenaDostava");

        $paymentMap = [
            $paymentInvoice => 'PR',
            $paymentCod => 'PP',
            $paymentDobavnica => 'DO',
            $paymentStripe => 'PY',
            $paymentPayapal => 'PAL',
            $paymentPayInShop => 'PG',
        ];

        $shippingMap = [
            $shippingPostaSlo => '20',
            $shippingGLS => '10',
            $shippingDPD => '07',
            $shippingPickupLJ => '98',
            $shippingPickupMB => '96',
            $shippingPickupNM => '97',
            $shippingPickupBrnik => '99',
            $shippingRobomatBrnik => '93',
            $shippingRobomatLj => '94',
            $shippingPOSTA => '38',
            $shippingPodgodbenaDostava => 'PD',
        ];

        return [
            'payment' => array_key_exists($paymentId, $paymentMap) ? $paymentMap[$paymentId] : '',
            'shipping' => array_key_exists($shippingId, $shippingMap) ? $shippingMap[$shippingId] : '',
        ];

    }

    private function reorderLineItems($lineItems) {
        // Step 1: Create a map of items by their id
        $itemMap = [];
        foreach ($lineItems as $item) {
            $itemMap[$item['id']] = $item;
        }

        // Step 2: Reorder the items based on parentId
        $orderedItems = [];
        $visited = [];

        foreach ($lineItems as $item) {
            if (!isset($item['attributes']['parentId']) && !isset($visited[$item['id']])) {
                $this->addItemWithChildren($orderedItems, $item, $itemMap, $visited);
            }
        }

        return $orderedItems;
    }

    private function addItemWithChildren(&$orderedItems, $item, $itemMap, &$visited) {
        if (isset($visited[$item['id']])) {
            return;
        }
        $visited[$item->id] = true;

        // Add the item to the ordered list
        $orderedItems[] = $item;

        // Collect children and separate those with productNumber "NANOSLEPILA"
        $children = [];
        $nanoslepilaChildren = [];

        foreach ($itemMap as $child) {
            if (isset($child['attributes']['parentId']) && $child['attributes']['parentId'] == $item->id) {
                // @phpstan-ignore-next-line
                if (isset($child['attributes']['payload']['productNumber']) && $child['attributes']['payload']['productNumber'] == "NANOSLEPILA") {
                    $nanoslepilaChildren[] = $child;
                } else {
                    $children[] = $child;
                }
            }
        }

        // Add regular children first
        foreach ($children as $child) {
            $this->addItemWithChildren($orderedItems, $child, $itemMap, $visited);
        }

        // Add "NANOSLEPILA" children last
        foreach ($nanoslepilaChildren as $child) {
            $this->addItemWithChildren($orderedItems, $child, $itemMap, $visited);
        }
    }

    //INTERFACE METHODS
    public function getName(): string
    {
        return 'Order export';
    }

    public function getSyncEndpoint(): string
    {
        return 'FA/narociloKupca';
    }

    public function getSyncArrayKey(): string
    {
        return '';
    }

    public function getSyncType(): string
    {
        return 'export';
    }

    public function getSyncOrigin(): string
    {
        return 'vasco';
    }

    public function getSyncCommandNames(): array
    {
        return ['order-export', 'export-order', 'export-orders', 'exportorders', 'exportorder', 'orderexport', 'ordersync'];
    }

    protected function getLockTtl(): int
    {
        return 120;
    }

}
