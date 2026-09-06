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
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Exports paid Shopware orders to Minimax as issued sales orders.
 *
 * Reads and writes through the DAL — one query with the associations it needs,
 * rather than a chain of admin-API round-trips per order.
 *
 * Minimax order rows carry prices WITHOUT VAT and cannot carry a VAT rate of
 * their own (see MinimaxClient), so every row is converted to net here, driven
 * by the order's own tax status.
 */
class OrderExportSync extends AbstractSyncBase
{
    /** Tolerance when reconciling the exported row total against the order. */
    private const RECONCILE_TOLERANCE = 0.02;

    private Context $context;

    /** Minimax item code used for the shipping row; empty = free-text row. */
    private string $shippingItemCode = '';

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
        $this->shippingItemCode = trim((string) ($this->systemConfigService->get('OptiwebSync.config.shippingItemCode') ?? ''));
    }

    // -------------------------------------------------------------------------
    // Selecting orders
    // -------------------------------------------------------------------------

    /**
     * @return array<int, OrderEntity>
     */
    protected function setExportData(): array
    {
        $orderExportState = (string) ($this->systemConfigService->get('OptiwebSync.config.orderExportState') ?? '');
        $allowedTxStates  = $this->systemConfigService->get('OptiwebSync.config.allowedOrderPaymentStateToExport');
        $allowedTxStates  = is_array($allowedTxStates) ? array_values(array_filter($allowedTxStates)) : [];

        // Without both settings the filter would be meaningless. Say so loudly
        // instead of quietly exporting nothing (or everything) forever.
        if ($orderExportState === '' || $allowedTxStates === []) {
            OwLogger::error($this->logger, 'Order export is not configured', [
                'orderExportState'                  => $orderExportState !== '' ? $orderExportState : 'MISSING',
                'allowedOrderPaymentStateToExport'  => $allowedTxStates !== [] ? $allowedTxStates : 'MISSING',
            ]);

            return [];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('customFields.' . GlobalVariables::CUSTOM_FIELD_OPTIWEB_STATUS, [
            GlobalVariables::STATUS_WAITING,
            GlobalVariables::STATUS_ERROR,
            GlobalVariables::STATUS_PARTIALLY_SENT,
        ]));
        $criteria->addFilter(new EqualsFilter('stateId', $orderExportState));
        $criteria->addFilter(new EqualsAnyFilter('transactions.stateId', $allowedTxStates));

        $criteria->addAssociation('currency');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('transactions.paymentMethod');

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
            $this->markOrder($order, GlobalVariables::STATUS_SENT, (string) $existingKey, null);

            return;
        }

        $transaction = $this->latestTransaction($order);
        $delivery    = $this->latestDelivery($order);

        if ($transaction === null) {
            ++$this->skipped;
            OwLogger::warning($this->logger, "Order $orderNumber has no payment transaction — not exported.");

            return;
        }

        $orderData = $this->buildOrderData($order, $transaction, $delivery);

        if (!$send) {
            $payload = $this->minimaxClient->buildOrderPayload($orderData);
            OwLogger::addVisibleLog($this->logger, "DRY RUN — order $orderNumber payload:");
            OwLogger::addLog($this->logger, 'Minimax payload', ['payload' => $payload]);
            OwLogger::addVisibleLog($this->logger, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        OwLogger::addVisibleLog($this->logger, "Sending order $orderNumber to Minimax.");
        $response = $this->minimaxClient->createOrder($orderData);

        if (($response['status'] ?? '') === 'ok') {
            ++$this->exported;
            $this->markOrder($order, GlobalVariables::STATUS_SENT, (string) $response['orderId'], null);
            OwLogger::addVisibleLog($this->logger, "Order $orderNumber exported as Minimax order " . $response['orderId'] . '.');

            return;
        }

        ++$this->failed;
        $error = (string) ($response['error'] ?? 'Unknown Minimax error');
        OwLogger::error($this->logger, "Order $orderNumber export rejected", ['error' => $error]);
        $this->markOrder($order, GlobalVariables::STATUS_ERROR, null, $error);
    }

    // -------------------------------------------------------------------------
    // Payload building
    // -------------------------------------------------------------------------

    /**
     * Build the neutral order array consumed by MinimaxClient::createOrder().
     */
    private function buildOrderData(
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
            'Order: ' . $orderNumber,
            $paymentName !== '' ? 'Payment: ' . $paymentName : '',
            $shippingName !== '' ? 'Shipping: ' . $shippingName : '',
            $order->getCustomerComment() !== null && $order->getCustomerComment() !== ''
                ? 'Comment: ' . $order->getCustomerComment()
                : '',
        ]));

        $orderDate = $order->getOrderDateTime();

        return [
            'date'        => $orderDate->format('Y-m-d'),
            'year'        => (int) $orderDate->format('Y'),
            'reference'   => $orderNumber,
            'note'        => $note,
            'currencyIso' => (string) ($order->getCurrency()?->getIsoCode() ?? 'EUR'),
            'customer'    => [
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
            ],
            'recipient'   => [
                'name'       => $shipping['name'],
                'address'    => $shipping['address'],
                'postalCode' => $shipping['postalCode'],
                'city'       => $shipping['city'],
                'countryIso' => $shipping['countryIso'],
            ],
            'lines'       => $lines,
        ];
    }

    /**
     * Every top-level line item as a Minimax row, at net unit prices.
     *
     * Promotions, credits and custom items are included as free-text rows: they
     * change what the customer paid, so leaving them out would hand Minimax a
     * document that cannot be reconciled against the shop.
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

            $payload  = $lineItem->getPayload() ?? [];
            $quantity = (float) $lineItem->getQuantity();

            $rows[] = [
                'sku'             => trim((string) ($payload['productNumber'] ?? '')),
                'name'            => (string) ($lineItem->getLabel() ?? ''),
                'quantity'        => $quantity !== 0.0 ? $quantity : 1.0,
                'unitPrice'       => $this->netUnitPrice($price, $taxStatus),
                'discountPercent' => 0.0,
            ];
        }

        return $rows;
    }

    /**
     * The shipping cost as its own row, net.
     *
     * @return array<int, array<string, mixed>>
     */
    private function shippingRows(OrderEntity $order, string $taxStatus, string $shippingName): array
    {
        $shippingCosts = $order->getShippingCosts();
        $total         = $shippingCosts->getTotalPrice();

        if (abs($total) < 0.0001) {
            return [];
        }

        $net = $taxStatus === CartPrice::TAX_STATE_GROSS
            ? $total - $shippingCosts->getCalculatedTaxes()->getAmount()
            : $total;

        return [[
            // A Minimax order row inherits its VAT rate from the referenced item
            // and has no rate of its own. With shippingItemCode configured the
            // row resolves to a real item and is taxed correctly; without it the
            // row goes over as free text and carries no VAT rate at all.
            'sku'             => $this->shippingItemCode,
            'name'            => $shippingName !== '' ? $shippingName : 'Poštnina',
            'quantity'        => 1.0,
            'unitPrice'       => round($net, 4),
            'discountPercent' => 0.0,
        ]];
    }

    /**
     * Net unit price for a calculated price, according to the order's tax status.
     *
     * A gross order stores gross unit prices, so the row has to be divided down
     * by its own tax rate; a net or tax-free order already stores net.
     */
    private function netUnitPrice(CalculatedPrice $price, string $taxStatus): float
    {
        $unitPrice = $price->getUnitPrice();

        if ($taxStatus !== CartPrice::TAX_STATE_GROSS) {
            return round($unitPrice, 4);
        }

        $rate = 0.0;
        foreach ($price->getTaxRules() as $taxRule) {
            $rate = $taxRule->getTaxRate();
            break;
        }

        if ($rate <= 0.0) {
            return round($unitPrice, 4);
        }

        return round($unitPrice / (1 + ($rate / 100)), 4);
    }

    /**
     * Warn when the exported rows do not add up to the order's net total.
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

        $expected = $order->getTaxStatus() === CartPrice::TAX_STATE_GROSS
            ? $order->getAmountNet()
            : $order->getAmountTotal();

        if (abs($rowTotal - $expected) > self::RECONCILE_TOLERANCE) {
            OwLogger::warning($this->logger, sprintf(
                'Order %s: exported rows total %.4f but the order net total is %.4f (difference %.4f).',
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
     * The most recent payment transaction.
     *
     * An order can hold several (a failed attempt followed by a successful one),
     * and the association is not ordered — so pick by creation time rather than
     * trusting whichever happens to come first.
     */
    private function latestTransaction(OrderEntity $order): ?OrderTransactionEntity
    {
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

    private function latestDelivery(OrderEntity $order): ?OrderDeliveryEntity
    {
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
            $customFields[GlobalVariables::CUSTOM_FIELD_OPTIWEB_KEY] = $minimaxId;
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
