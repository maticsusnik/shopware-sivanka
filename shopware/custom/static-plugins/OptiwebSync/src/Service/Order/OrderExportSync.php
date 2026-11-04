<?php declare(strict_types=1);

namespace OptiwebSync\Service\Order;

use Doctrine\DBAL\Connection;
use Monolog\Logger;
use OptiwebSync\Client\MinimaxClient;
use OptiwebSync\Helper\GlobalVariables;
use OptiwebSync\Helper\OwLogger;
use OptiwebSync\Service\SyncBase\AbstractSyncBase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Exports open Shopware orders to Minimax as DRAFT issued invoices (Izdani
 * računi) on the shop's own numbering series — WEB-02 "SPLETNA TRG. davčna
 * blagajna" for Sivanka. Staff issue (number and fiscalise) them in Minimax;
 * OrderStatusSync then brings the invoice number back and completes the order.
 *
 * Which orders: order state "open" only. Offline payment methods (cash on
 * delivery, invoice, prepayment, direct debit) go over as soon as the order is
 * open; any other method — card and other online payments (Stripe, PayPal) —
 * only once its payment state is "paid". Always judged on the order's current
 * (primary) transaction, so after a customer switches the payment method the
 * new one counts.
 *
 * The shop payment method is mapped onto a Minimax invoice payment method
 * (Kartica, Transakcijski račun, Gotovina po povzetju, …), marked "already
 * paid" when the shop transaction is paid.
 *
 * Reads and writes through the DAL — one query with the associations it needs,
 * rather than a chain of admin-API round-trips per order.
 *
 * Rows are written in the form the organisation keeps its item prices in —
 * gross for Sivanka (items/settings PricesIncludeVAT = D) — each with its VAT
 * percent, which an invoice requires. See rowUnitPrice().
 */
class OrderExportSync extends AbstractSyncBase
{
    /** Tolerance when reconciling the exported row total against the order. */
    private const RECONCILE_TOLERANCE = 0.02;

    private Context $context;

    /** Payment handlers that never take money online: exportable while unpaid. */
    private const OFFLINE_PAYMENT_HANDLERS = [
        'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\CashPayment',
        'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\InvoicePayment',
        'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\PrePayment',
        'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\DebitPayment',
    ];

    /** The Minimax shipping item (no Code there, so it is found by name). */
    private const DEFAULT_SHIPPING_ITEM = 'POŠTNINA';

    /** Minimax customer for buyers without a VAT ID (B2C). */
    private const DEFAULT_END_CUSTOMER = 'končni kupec';

    /** Minimax warehouse (code) the goods are taken from: "1" = Skladišče. */
    private const DEFAULT_WAREHOUSE = '1';

    /** Minimax invoice numbering series (code or name) the drafts are filed on. */
    private const DEFAULT_NUMBERING = 'WEB-02';

    /**
     * Shop payment method → Minimax invoice payment method (config key, default
     * name). Matched in this order on the handler identifier or technical name;
     * anything unmatched is an online payment and counts as "card".
     */
    private const PAYMENT_METHOD_MAP = [
        'CashPayment'    => ['paymentMethodCashOnDelivery', 'Gotovina po povzetju'],
        'InvoicePayment' => ['paymentMethodBankTransfer', 'Transakcijski račun'],
        'PrePayment'     => ['paymentMethodBankTransfer', 'Transakcijski račun'],
        'DebitPayment'   => ['paymentMethodBankTransfer', 'Transakcijski račun'],
        'paypal'         => ['paymentMethodPaypal', 'Drugo'],
    ];
    private const PAYMENT_METHOD_CARD = ['paymentMethodCard', 'Kartica'];

    /** Code of the shared Minimax customer every order without a VAT ID goes to. */
    private string $endCustomerCode = self::DEFAULT_END_CUSTOMER;

    private string $numbering = self::DEFAULT_NUMBERING;

    /** Code or exact name of the Minimax item every shipping row references. */
    private string $shippingItem = self::DEFAULT_SHIPPING_ITEM;

    /** Code or exact name of the Minimax warehouse every item row is taken from. */
    private string $warehouse = self::DEFAULT_WAREHOUSE;

    /** Row prices in the form Minimax's own item prices are kept in (items/settings). */
    private bool $grossPrices = true;

    private int $exported = 0;
    private int $failed = 0;
    private int $skipped = 0;

    public function __construct(
        SystemConfigService $systemConfigService,
        Connection $connection,
        private readonly MinimaxClient $minimaxClient,
        private readonly EntityRepository $orderRepository,
    ) {
        parent::__construct($systemConfigService, $connection);
    }

    // -------------------------------------------------------------------------
    // SyncBaseInterface
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return 'Order export';
    }

    public function getSyncType(): string
    {
        return 'export';
    }

    public function getSyncOrigin(): string
    {
        return 'minimax';
    }

    public function getSyncCommandNames(): array
    {
        return ['order-export', 'export-order', 'export-orders', 'exportorders', 'exportorder', 'orderexport', 'ordersync'];
    }

    protected function getLockTtl(): int
    {
        return 600;
    }

    protected function createLogger(): Logger
    {
        return OwLogger::generate('OrderExportSync', 'order-export-sync', true);
    }

    protected function initialize(): void
    {
        $this->context          = Context::createDefaultContext();
        $this->shippingItem = trim((string) ($this->systemConfigService->get('OptiwebSync.config.shippingItemCode') ?? ''))
            ?: self::DEFAULT_SHIPPING_ITEM;
        $this->endCustomerCode = trim((string) ($this->systemConfigService->get('OptiwebSync.config.endCustomerCode') ?? ''))
            ?: self::DEFAULT_END_CUSTOMER;
        $this->warehouse = trim((string) ($this->systemConfigService->get('OptiwebSync.config.warehouseCode') ?? ''))
            ?: self::DEFAULT_WAREHOUSE;
        $this->numbering = $this->configString('documentNumbering', self::DEFAULT_NUMBERING);
        $this->grossPrices = $this->minimaxClient->pricesIncludeVat();
    }

    // -------------------------------------------------------------------------
    // Selecting orders
    // -------------------------------------------------------------------------

    /**
     * @return array<int, OrderEntity>
     */
    protected function setExportData(): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('customFields.' . GlobalVariables::CUSTOM_FIELD_OPTIWEB_STATUS, [
            GlobalVariables::STATUS_WAITING,
            GlobalVariables::STATUS_ERROR,
            GlobalVariables::STATUS_UNCONFIRMED,
            GlobalVariables::STATUS_PARTIALLY_SENT,
        ]));
        $criteria->addFilter(new EqualsFilter('stateMachineState.technicalName', OrderStates::STATE_OPEN));
        // Offline payment, or paid. Orders written without a primary transaction
        // reference fall back to "any transaction"; exportOrder() re-checks the
        // latest one, so the fallback can only let too many through, never too few.
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new EqualsAnyFilter('primaryOrderTransaction.paymentMethod.handlerIdentifier', self::OFFLINE_PAYMENT_HANDLERS),
            new EqualsFilter('primaryOrderTransaction.stateMachineState.technicalName', OrderTransactionStates::STATE_PAID),
            new MultiFilter(MultiFilter::CONNECTION_AND, [
                new EqualsFilter('primaryOrderTransactionId', null),
                new MultiFilter(MultiFilter::CONNECTION_OR, [
                    new EqualsAnyFilter('transactions.paymentMethod.handlerIdentifier', self::OFFLINE_PAYMENT_HANDLERS),
                    new EqualsFilter('transactions.stateMachineState.technicalName', OrderTransactionStates::STATE_PAID),
                ]),
            ]),
        ]));

        $criteria->addAssociation('currency');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->addAssociation('primaryOrderTransaction.stateMachineState');
        $criteria->addAssociation('primaryOrderDelivery.shippingOrderAddress.country');
        $criteria->addAssociation('primaryOrderDelivery.shippingMethod');
        $criteria->addAssociation('primaryOrderTransaction.paymentMethod');

        $criteria->addSorting(new FieldSorting('orderDateTime', FieldSorting::ASCENDING));
        $criteria->setLimit(GlobalVariables::BATCH_SIZE);

        /** @var array<int, OrderEntity> $orders */
        $orders = $this->orderRepository->search($criteria, $this->context)->getEntities()->getElements();

        return array_values($orders);
    }

    /**
     * @param array<int, OrderEntity> $dataArray
     */
    protected function export(array $dataArray, array $loopData): int
    {
        $exportEnabled = (bool) $this->systemConfigService->get('OptiwebSync.config.enableOrderExport');

        if (!$exportEnabled && !$this->dryRun) {
            OwLogger::addVisibleLog(
                $this->logger,
                'Order export is disabled (OptiwebSync.config.enableOrderExport) — running as a dry run instead. '
                . 'No orders will be sent to Minimax.'
            );
        }

        $send = $exportEnabled && !$this->dryRun;

        foreach ($dataArray as $order) {
            // A per-order guard: one unexportable order must not take the batch
            // down with it.
            try {
                $this->exportOrder($order, $send);
            } catch (\Throwable $e) {
                ++$this->failed;
                OwLogger::exception($this->logger, 'Order ' . $order->getOrderNumber() . ' export failed', $e);
                $this->markOrder($order, GlobalVariables::STATUS_ERROR, null, $e->getMessage());
            }
        }

        $this->logClientWarnings();
        OwLogger::addVisibleLog($this->logger, sprintf(
            'Order export totals: %d exported, %d failed, %d skipped.',
            $this->exported,
            $this->failed,
            $this->skipped,
        ));

        return $this->exported;
    }

    private function exportOrder(OrderEntity $order, bool $send): void
    {
        $orderNumber = (string) $order->getOrderNumber();
        $customFields = $order->getCustomFields() ?? [];

        // Already in Minimax: never risk a second document for the same order.
        $existingKey = $customFields[GlobalVariables::CUSTOM_FIELD_OPTIWEB_KEY] ?? null;
        if (!empty($existingKey)) {
            ++$this->skipped;
            OwLogger::addVisibleLog($this->logger, "Order $orderNumber already exported (Minimax id $existingKey) — skipping.");
            // The key is already stored; passing null leaves it — and its document type — untouched.
            $this->markOrder($order, GlobalVariables::STATUS_SENT, null, null);

            return;
        }

        $transaction = $this->latestTransaction($order);
        $delivery    = $this->latestDelivery($order);

        if ($transaction === null) {
            ++$this->skipped;
            OwLogger::warning($this->logger, "Order $orderNumber has no payment transaction — not exported.");

            return;
        }

        // Left waiting: picked up by a later run once the payment arrives.
        if (!$this->isPaymentReady($transaction)) {
            ++$this->skipped;
            OwLogger::addVisibleLog($this->logger, "Order $orderNumber: online payment not paid yet — not exported for now.");

            return;
        }

        $invoiceData = $this->buildInvoiceData($order, $transaction, $delivery);
        $reference   = $invoiceData['reference'];

        if (!$send) {
            $existingId = $this->minimaxClient->findInvoiceIdByReference($reference, $this->numbering);
            if ($existingId !== null) {
                OwLogger::addVisibleLog($this->logger, "DRY RUN — order $orderNumber is already Minimax invoice $existingId, would only link it.");

                return;
            }

            $payload = $this->minimaxClient->buildInvoicePayload($invoiceData);
            OwLogger::addVisibleLog($this->logger, "DRY RUN — order $orderNumber invoice payload:");
            OwLogger::addLog($this->logger, 'Minimax payload', ['payload' => $payload]);
            OwLogger::addVisibleLog($this->logger, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        // An earlier POST may have been applied: only ever link it, never resend
        // on our own. Setting the status back to "waiting" resends it.
        if (($customFields[GlobalVariables::CUSTOM_FIELD_OPTIWEB_STATUS] ?? null) === GlobalVariables::STATUS_UNCONFIRMED) {
            $existingId = $this->minimaxClient->findInvoiceIdByReference($reference, $this->numbering);
            if ($existingId !== null) {
                ++$this->skipped;
                $this->markOrder($order, GlobalVariables::STATUS_SENT, (string) $existingId, null);
                OwLogger::addVisibleLog($this->logger, "Order $orderNumber found as Minimax invoice $existingId — linked.");

                return;
            }

            ++$this->skipped;
            OwLogger::warning($this->logger, "Order $orderNumber is unconfirmed and not found in Minimax — not resent. Check Minimax, then set its Minimax export status to \"waiting\" to resend.");

            return;
        }

        OwLogger::addVisibleLog($this->logger, "Sending order $orderNumber to Minimax as a draft invoice on {$this->numbering}.");
        $response = $this->minimaxClient->createInvoice($invoiceData);

        $status = $response['status'] ?? '';

        if ($status === 'ok') {
            ++$this->exported;
            $this->markOrder($order, GlobalVariables::STATUS_SENT, (string) $response['invoiceId'], null);
            OwLogger::addVisibleLog($this->logger, "Order $orderNumber exported as Minimax draft invoice " . $response['invoiceId'] . '.');

            return;
        }

        // Filed by an earlier attempt whose answer was lost: link it, never resend.
        if ($status === 'existing') {
            ++$this->skipped;
            $this->markOrder($order, GlobalVariables::STATUS_SENT, (string) $response['invoiceId'], null);
            OwLogger::addVisibleLog($this->logger, "Order $orderNumber is already Minimax invoice " . $response['invoiceId'] . ' — linked, not sent again.');

            return;
        }

        ++$this->failed;
        $error = (string) ($response['error'] ?? 'Unknown Minimax error');

        if ($status === 'uncertain') {
            OwLogger::error($this->logger, "Order $orderNumber may have been filed in Minimax — later runs only look it up, they do not resend it", ['error' => $error]);
            $this->markOrder($order, GlobalVariables::STATUS_UNCONFIRMED, null, $error);

            return;
        }

        OwLogger::error($this->logger, "Order $orderNumber export rejected", ['error' => $error]);
        $this->markOrder($order, GlobalVariables::STATUS_ERROR, null, $error);
    }

    // -------------------------------------------------------------------------
    // Payload building
    // -------------------------------------------------------------------------

    /**
     * Build the neutral invoice array consumed by MinimaxClient::createInvoice().
     */
    private function buildInvoiceData(
        OrderEntity $order,
        OrderTransactionEntity $transaction,
        ?OrderDeliveryEntity $delivery,
    ): array {
        $orderNumber = (string) $order->getOrderNumber();
        $taxStatus   = (string) $order->getTaxStatus();

        $billing  = $this->addressData($order->getBillingAddress());
        $shipping = $this->addressData($delivery?->getShippingOrderAddress());

        $orderCustomer = $order->getOrderCustomer();
        $email         = trim((string) $orderCustomer?->getEmail());
        $vatIds        = $orderCustomer?->getVatIds() ?? [];
        $vatId         = trim((string) ($vatIds[0] ?? ''));

        $paymentName  = (string) $transaction->getPaymentMethod()?->getName();
        $shippingName = (string) $delivery?->getShippingMethod()?->getName();

        $lines = $this->lineItemRows($order, $taxStatus);
        $lines = array_merge($lines, $this->shippingRows($order, $taxStatus, $shippingName));

        $this->reconcile($order, $lines, $orderNumber);

        $note = implode(' | ', array_filter([
            'Spletno naročilo: ' . $orderNumber,
            $paymentName !== '' ? 'Plačilo: ' . $paymentName : '',
            $shippingName !== '' ? 'Dostava: ' . $shippingName : '',
            $order->getCustomerComment() !== null && $order->getCustomerComment() !== ''
                ? 'Komentar: ' . $order->getCustomerComment()
                : '',
        ]));

        $isPaid = $transaction->getStateMachineState()?->getTechnicalName() === OrderTransactionStates::STATE_PAID;

        return [
            // Dated the day it lands in Minimax; staff issue it from there.
            'date'            => (new \DateTimeImmutable())->format('Y-m-d'),
            'transactionDate' => $order->getOrderDateTime()->format('Y-m-d'),
            'reference'       => $orderNumber,
            'note'            => $note,
            'currencyIso'     => (string) ($order->getCurrency()?->getIsoCode() ?? 'EUR'),
            'numbering'       => $this->numbering,
            'warehouse'       => $this->warehouse,
            'payment'         => [
                'method' => $this->minimaxPaymentMethod($transaction),
                'amount' => $order->getAmountTotal(),
                'paid'   => $isPaid,
            ],
            'customer'        => [
                'name'       => $billing['name'] !== '' ? $billing['name'] : ($email !== '' ? $email : $orderNumber),
                'email'      => $email,
                // Minimax has no e-mail field on a customer, and Code is unique
                // per organisation — so the e-mail doubles as the lookup key.
                'code'       => $email !== '' ? $email : $orderNumber,
                'address'    => $billing['address'],
                'postalCode' => $billing['postalCode'],
                'city'       => $billing['city'],
                'countryIso' => $billing['countryIso'] !== '' ? $billing['countryIso'] : 'SI',
                'vatId'      => $vatId,
                // Without a VAT ID the invoice goes to the shared B2C customer; the
                // buyer's name and address still go on it (Addressee* fields).
                'endCustomerCode' => $vatId === '' ? $this->endCustomerCode : '',
            ],
            'recipient'       => [
                'name'       => $shipping['name'],
                'address'    => $shipping['address'],
                'postalCode' => $shipping['postalCode'],
                'city'       => $shipping['city'],
                'countryIso' => $shipping['countryIso'],
            ],
            'lines'           => $lines,
        ];
    }

    /** The Minimax invoice payment method name for the order's payment method. */
    private function minimaxPaymentMethod(OrderTransactionEntity $transaction): string
    {
        $method     = $transaction->getPaymentMethod();
        $identifier = (string) $method?->getHandlerIdentifier() . ' ' . (string) $method?->getTechnicalName();

        [$configKey, $default] = self::PAYMENT_METHOD_CARD;
        foreach (self::PAYMENT_METHOD_MAP as $needle => $target) {
            if (stripos($identifier, $needle) !== false) {
                [$configKey, $default] = $target;
                break;
            }
        }

        return $this->configString($configKey, $default);
    }

    private function configString(string $key, string $default): string
    {
        return trim((string) ($this->systemConfigService->get('OptiwebSync.config.' . $key) ?? '')) ?: $default;
    }

    /**
     * Every top-level line item as a Minimax row.
     *
     * Promotions, credits and custom items are included as free-text rows: they
     * change what the customer paid, so leaving them out would hand Minimax a
     * document that cannot be reconciled against the shop. A row whose price
     * spans several VAT rates (a cart-wide promotion over 22 % and 9,5 % goods)
     * is split into one row per rate, since an invoice row has exactly one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineItemRows(OrderEntity $order, string $taxStatus): array
    {
        $lineItems = $order->getLineItems()?->getElements() ?? [];

        // Only top-level rows: children of a container line item are already
        // accounted for by their parent's price.
        $lineItems = array_filter($lineItems, static fn (OrderLineItemEntity $item): bool => $item->getParentId() === null);

        usort($lineItems, static fn (OrderLineItemEntity $a, OrderLineItemEntity $b): int => $a->getPosition() <=> $b->getPosition());

        $rows = [];

        foreach ($lineItems as $lineItem) {
            $price = $lineItem->getPrice();
            if ($price === null) {
                continue;
            }

            $payload = $lineItem->getPayload() ?? [];
            $sku     = trim((string) ($payload['productNumber'] ?? ''));
            $name    = (string) ($lineItem->getLabel() ?? '');

            $split = $this->splitByTaxRate($price, $taxStatus, $name);
            if ($split !== null) {
                array_push($rows, ...$split);
                continue;
            }

            $quantity = (float) $lineItem->getQuantity();

            $rows[] = [
                'sku'        => $sku,
                'name'       => $name,
                'quantity'   => $quantity !== 0.0 ? $quantity : 1.0,
                'unitPrice'  => $this->rowUnitPrice($price->getUnitPrice(), $this->taxRate($price), $taxStatus),
                'vatPercent' => $this->rowVatPercent($this->taxRate($price), $taxStatus),
            ];
        }

        return $rows;
    }

    /**
     * The shipping cost as its own row (one per VAT rate when it spans several).
     *
     * @return array<int, array<string, mixed>>
     */
    private function shippingRows(OrderEntity $order, string $taxStatus, string $shippingName): array
    {
        $shippingCosts = $order->getShippingCosts();

        if (abs($shippingCosts->getTotalPrice()) < 0.0001) {
            return [];
        }

        $name = $shippingName !== '' ? $shippingName : 'Poštnina';
        $rows = $this->splitByTaxRate($shippingCosts, $taxStatus, $name) ?? [[
            'name'       => $name,
            'quantity'   => 1.0,
            'unitPrice'  => $this->rowUnitPrice($shippingCosts->getTotalPrice(), $this->taxRate($shippingCosts), $taxStatus),
            'vatPercent' => $this->rowVatPercent($this->taxRate($shippingCosts), $taxStatus),
        ]];

        // Point every shipping row at Minimax's shipping item ("POŠTNINA", no
        // Code, hence the name lookup), so it books like the till's shipping.
        foreach ($rows as &$row) {
            $row['sku']           = '';
            $row['itemReference'] = $this->shippingItem;
        }

        return $rows;
    }

    /**
     * One quantity-1 row per VAT rate for a price that carries several, or null
     * when it carries at most one.
     *
     * A CalculatedTax's price is the part of the total that rate applies to — in
     * the order's own form (gross for a gross order, net for a net one).
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function splitByTaxRate(CalculatedPrice $price, string $taxStatus, string $name): ?array
    {
        $taxes = $price->getCalculatedTaxes()->getElements();
        if (count($taxes) < 2 || $taxStatus === CartPrice::TAX_STATE_FREE) {
            return null;
        }

        $rows = [];
        foreach ($taxes as $tax) {
            $rate = $tax->getTaxRate();
            $rows[] = [
                'sku'        => '',
                'name'       => sprintf('%s (DDV %s %%)', $name, str_replace('.', ',', (string) $rate)),
                'quantity'   => 1.0,
                'unitPrice'  => $this->rowUnitPrice($tax->getPrice(), $rate, $taxStatus),
                'vatPercent' => $rate,
            ];
        }

        return $rows;
    }

    private function taxRate(CalculatedPrice $price): float
    {
        foreach ($price->getCalculatedTaxes() as $tax) {
            return $tax->getTaxRate();
        }
        foreach ($price->getTaxRules() as $taxRule) {
            return $taxRule->getTaxRate();
        }

        return 0.0;
    }

    private function rowVatPercent(float $rate, string $taxStatus): float
    {
        return $taxStatus === CartPrice::TAX_STATE_FREE ? 0.0 : $rate;
    }

    /**
     * Unit price for a Minimax invoice row, in the form the organisation keeps
     * its item prices in — with VAT when items/settings says "D" (Sivanka), the
     * invoice then going out with PricesOnInvoice D — without otherwise.
     *
     * A gross order stores gross unit prices, a net order net ones; a tax-free
     * order has no VAT either way.
     */
    private function rowUnitPrice(float $unitPrice, float $rate, string $taxStatus): float
    {
        if ($taxStatus === CartPrice::TAX_STATE_FREE || $rate <= 0.0) {
            return round($unitPrice, 4);
        }

        if ($this->grossPrices) {
            return round($taxStatus === CartPrice::TAX_STATE_NET ? $unitPrice * (1 + ($rate / 100)) : $unitPrice, 4);
        }

        return round($taxStatus === CartPrice::TAX_STATE_GROSS ? $unitPrice / (1 + ($rate / 100)) : $unitPrice, 4);
    }

    /**
     * Warn when the exported rows do not add up to the order's total (gross or
     * net, whichever form the rows are in).
     *
     * Cheap insurance: a rounding or tax-status mistake shows up here as a
     * logged discrepancy instead of as a bookkeeping problem weeks later.
     *
     * @param array<int, array<string, mixed>> $lines
     */
    private function reconcile(OrderEntity $order, array $lines, string $orderNumber): void
    {
        $rowTotal = 0.0;
        foreach ($lines as $line) {
            $rowTotal += ((float) $line['unitPrice']) * ((float) $line['quantity']);
        }

        $expected = $this->grossPrices ? $order->getAmountTotal() : $order->getAmountNet();

        if (abs($rowTotal - $expected) > self::RECONCILE_TOLERANCE) {
            OwLogger::warning($this->logger, sprintf(
                'Order %s: exported rows total %.4f but the order total is %.4f (difference %.4f).',
                $orderNumber,
                $rowTotal,
                $expected,
                $rowTotal - $expected,
            ));
        }
    }

    // -------------------------------------------------------------------------
    // Order association helpers
    // -------------------------------------------------------------------------

    /**
     * The order's primary payment transaction.
     *
     * Falls back to the most recent transaction for orders without a primary
     * reference: an order can hold several (a failed attempt followed by a
     * successful one), and the association is not ordered — so pick by creation
     * time rather than trusting whichever happens to come first.
     */
    private function latestTransaction(OrderEntity $order): ?OrderTransactionEntity
    {
        $primary = $order->getPrimaryOrderTransaction();
        if ($primary !== null) {
            return $primary;
        }

        $transactions = $order->getTransactions()?->getElements() ?? [];
        if ($transactions === []) {
            return null;
        }

        usort(
            $transactions,
            static fn (OrderTransactionEntity $a, OrderTransactionEntity $b): int => $a->getCreatedAt() <=> $b->getCreatedAt()
        );

        return end($transactions) ?: null;
    }

    /** Offline payment methods are ready right away; online ones once paid. */
    private function isPaymentReady(OrderTransactionEntity $transaction): bool
    {
        $handler = (string) $transaction->getPaymentMethod()?->getHandlerIdentifier();
        if (\in_array($handler, self::OFFLINE_PAYMENT_HANDLERS, true)) {
            return true;
        }

        return $transaction->getStateMachineState()?->getTechnicalName() === OrderTransactionStates::STATE_PAID;
    }

    /**
     * The order's primary delivery, falling back to the most recent one for orders
     * without a primary reference.
     */
    private function latestDelivery(OrderEntity $order): ?OrderDeliveryEntity
    {
        $primary = $order->getPrimaryOrderDelivery();
        if ($primary !== null) {
            return $primary;
        }

        $deliveries = $order->getDeliveries()?->getElements() ?? [];
        if ($deliveries === []) {
            return null;
        }

        usort(
            $deliveries,
            static fn (OrderDeliveryEntity $a, OrderDeliveryEntity $b): int => $a->getCreatedAt() <=> $b->getCreatedAt()
        );

        return end($deliveries) ?: null;
    }

    /**
     * Flatten an order address, preferring the company name over the person's.
     *
     * @return array{name: string, address: string, postalCode: string, city: string, countryIso: string}
     */
    private function addressData(?OrderAddressEntity $address): array
    {
        if ($address === null) {
            return ['name' => '', 'address' => '', 'postalCode' => '', 'city' => '', 'countryIso' => ''];
        }

        $company = trim((string) $address->getCompany());
        $person  = trim(trim((string) $address->getFirstName()) . ' ' . trim((string) $address->getLastName()));

        return [
            'name'       => $company !== '' ? $company : $person,
            'address'    => trim((string) $address->getStreet()),
            'postalCode' => trim((string) $address->getZipcode()),
            'city'       => trim((string) $address->getCity()),
            'countryIso' => trim((string) $address->getCountry()?->getIso()),
        ];
    }

    // -------------------------------------------------------------------------
    // Write-back
    // -------------------------------------------------------------------------

    /**
     * Record the export outcome on the order's custom fields.
     *
     * The existing custom fields are merged explicitly so a partial write can
     * never drop another plugin's keys, and a successful export clears any error
     * left over from a previous attempt.
     */
    private function markOrder(OrderEntity $order, string $status, ?string $minimaxId, ?string $error): void
    {
        if ($this->dryRun) {
            return;
        }

        $customFields = $order->getCustomFields() ?? [];
        $customFields[GlobalVariables::CUSTOM_FIELD_OPTIWEB_STATUS] = $status;

        if ($minimaxId !== null) {
            $customFields[GlobalVariables::CUSTOM_FIELD_OPTIWEB_KEY]      = $minimaxId;
            $customFields[GlobalVariables::CUSTOM_FIELD_OPTIWEB_DOCUMENT] = GlobalVariables::MINIMAX_DOCUMENT_INVOICE;
        }

        $customFields[GlobalVariables::CUSTOM_FIELD_OPTIWEB_ERROR] = $error;

        try {
            $this->orderRepository->update([[
                'id'           => $order->getId(),
                'customFields' => $customFields,
            ]], $this->context);
        } catch (\Throwable $e) {
            OwLogger::error($this->logger, 'Could not write export status back to order ' . $order->getOrderNumber(), [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function logClientWarnings(): void
    {
        foreach ($this->minimaxClient->takeWarnings() as $warning) {
            OwLogger::warning($this->logger, 'Minimax: ' . $warning);
        }
    }
}
