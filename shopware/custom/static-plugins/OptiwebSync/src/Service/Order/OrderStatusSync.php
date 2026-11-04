<?php declare(strict_types=1);

namespace OptiwebSync\Service\Order;

use Doctrine\DBAL\Connection;
use Monolog\Logger;
use OptiwebSync\Client\MinimaxClient;
use OptiwebSync\Helper\GlobalVariables;
use OptiwebSync\Helper\OwLogger;
use OptiwebSync\Service\SyncBase\AbstractSyncBase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Carries the Minimax document status back onto the Shopware order.
 *
 * Invoices (every export since 2026-11): once staff issue the draft in Minimax,
 * its number (e.g. WEB-02-2026000012, the one in the invoice's barcode) is
 * stored on the order, the order moves to "Done" and its export status to
 * "done". A draft deleted in Minimax marks the export "cancelled" — never
 * re-exported on its own.
 *
 * Received orders exported before that: Minimax "Potrjeno" (P) moves the order
 * to "In progress", "Zaključeno" (Z) to "Done".
 *
 * Always through the regular state transitions, so the customer gets the same
 * status mails as for a change made in the admin. Only ever forwards: an order
 * is never moved back.
 *
 * Only orders this plugin exported (status "sent", with a Minimax id) are
 * looked at.
 */
class OrderStatusSync extends AbstractSyncBase
{
    /** Minimax status → Shopware order state it leads to. */
    private const STATE_FOR_MINIMAX_STATUS = [
        'P' => OrderStates::STATE_IN_PROGRESS,
        'Z' => OrderStates::STATE_COMPLETED,
    ];

    /** Transitions to walk from a state to a target, in order. */
    private const TRANSITIONS = [
        OrderStates::STATE_OPEN => [
            OrderStates::STATE_IN_PROGRESS => [StateMachineTransitionActions::ACTION_PROCESS],
            OrderStates::STATE_COMPLETED   => [StateMachineTransitionActions::ACTION_PROCESS, StateMachineTransitionActions::ACTION_COMPLETE],
        ],
        OrderStates::STATE_IN_PROGRESS => [
            OrderStates::STATE_COMPLETED => [StateMachineTransitionActions::ACTION_COMPLETE],
        ],
    ];

    private Context $context;

    private int $updated = 0;
    private int $failed = 0;

    public function __construct(
        SystemConfigService $systemConfigService,
        Connection $connection,
        private readonly MinimaxClient $minimaxClient,
        private readonly EntityRepository $orderRepository,
        private readonly StateMachineRegistry $stateMachineRegistry,
    ) {
        parent::__construct($systemConfigService, $connection);
    }

    // -------------------------------------------------------------------------
    // SyncBaseInterface
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return 'Order status';
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
        return ['order-status', 'orderstatus', 'order-statuses'];
    }

    protected function getLockTtl(): int
    {
        return 600;
    }

    protected function createLogger(): Logger
    {
        return OwLogger::generate('OrderStatusSync', 'order-status-sync', true);
    }

    protected function initialize(): void
    {
        $this->context = Context::createDefaultContext();
        $this->updated = 0;
        $this->failed  = 0;
    }

    // -------------------------------------------------------------------------
    // Sync
    // -------------------------------------------------------------------------

    /**
     * @return array<int, OrderEntity>
     */
    protected function setExportData(): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.' . GlobalVariables::CUSTOM_FIELD_OPTIWEB_STATUS, GlobalVariables::STATUS_SENT));
        // Completed too: staff may finish an order before issuing its invoice,
        // and the invoice number still has to come back.
        $criteria->addFilter(new EqualsAnyFilter('stateMachineState.technicalName', [
            ...array_keys(self::TRANSITIONS),
            OrderStates::STATE_COMPLETED,
        ]));
        $criteria->addAssociation('stateMachineState');
        $criteria->addSorting(new FieldSorting('orderDateTime', FieldSorting::ASCENDING));

        if ($this->setId !== null) {
            $criteria->addFilter(new EqualsFilter('orderNumber', $this->setId));
        }

        return array_values($this->orderRepository->search($criteria, $this->context)->getElements());
    }

    /**
     * @param array<int, OrderEntity> $dataArray
     */
    protected function export(array $dataArray, array $loopData): int
    {
        foreach ($dataArray as $order) {
            // One order Minimax will not answer for must not stop the others.
            try {
                $this->updateOrder($order);
            } catch (\Throwable $e) {
                ++$this->failed;
                OwLogger::exception($this->logger, 'Order ' . $order->getOrderNumber() . ' status update failed', $e);
            }
        }

        OwLogger::addVisibleLog($this->logger, sprintf(
            'Order status totals: %d checked, %d updated, %d failed.',
            count($dataArray),
            $this->updated,
            $this->failed,
        ));

        return $this->updated;
    }

    private function updateOrder(OrderEntity $order): void
    {
        $customFields = $order->getCustomFields() ?? [];
        $minimaxId    = (int) ($customFields[GlobalVariables::CUSTOM_FIELD_OPTIWEB_KEY] ?? 0);
        if ($minimaxId <= 0) {
            return;
        }

        if (($customFields[GlobalVariables::CUSTOM_FIELD_OPTIWEB_DOCUMENT] ?? null) === GlobalVariables::MINIMAX_DOCUMENT_INVOICE) {
            $this->updateFromInvoice($order, $minimaxId);

            return;
        }

        $this->updateFromReceivedOrder($order, $minimaxId);
    }

    private function updateFromInvoice(OrderEntity $order, int $invoiceId): void
    {
        $orderNumber = (string) $order->getOrderNumber();
        $invoice     = $this->minimaxClient->getInvoice($invoiceId);

        if (!$invoice['found']) {
            OwLogger::warning($this->logger, "Order $orderNumber: Minimax invoice $invoiceId no longer exists (draft deleted) — export marked cancelled, not resent.");
            if ($this->dryRun) {
                return;
            }
            $this->writeCustomFields($order, [
                GlobalVariables::CUSTOM_FIELD_OPTIWEB_STATUS => GlobalVariables::STATUS_CANCELLED,
                GlobalVariables::CUSTOM_FIELD_OPTIWEB_ERROR  => "Minimax invoice $invoiceId was deleted",
            ]);

            return;
        }

        // Still a draft: nothing to do until staff issue it.
        if ($invoice['status'] !== 'I') {
            return;
        }

        $current = (string) $order->getStateMachineState()?->getTechnicalName();
        $actions = self::TRANSITIONS[$current][OrderStates::STATE_COMPLETED] ?? [];
        $number  = $invoice['number'];

        if ($this->dryRun) {
            OwLogger::addVisibleLog($this->logger, sprintf(
                'DRY RUN — order %s: Minimax invoice %s issued, would store the number%s.',
                $orderNumber,
                $number ?? '(number unknown)',
                $actions !== [] ? " and move $current → " . OrderStates::STATE_COMPLETED : '',
            ));

            return;
        }

        // Number first: a failed transition must not lose it, and the order
        // leaves this sync's selection once its status is "done".
        $this->writeCustomFields($order, [
            GlobalVariables::CUSTOM_FIELD_OPTIWEB_INVOICE_NUMBER => $number,
            GlobalVariables::CUSTOM_FIELD_OPTIWEB_STATUS         => GlobalVariables::STATUS_DONE,
            GlobalVariables::CUSTOM_FIELD_OPTIWEB_ERROR          => null,
        ]);

        $this->transition($order, $actions);

        ++$this->updated;
        OwLogger::addVisibleLog($this->logger, sprintf(
            'Order %s: Minimax invoice %s issued%s.',
            $orderNumber,
            $number ?? '(number unknown)',
            $actions !== [] ? " — moved $current → " . OrderStates::STATE_COMPLETED : '',
        ));
    }

    private function updateFromReceivedOrder(OrderEntity $order, int $minimaxId): void
    {
        $orderNumber = (string) $order->getOrderNumber();
        $current     = (string) $order->getStateMachineState()?->getTechnicalName();
        if (!isset(self::TRANSITIONS[$current])) {
            return;
        }

        $minimaxStatus = $this->minimaxClient->getOrderStatus($minimaxId);
        $target        = self::STATE_FOR_MINIMAX_STATUS[$minimaxStatus] ?? null;
        $actions       = $target !== null ? (self::TRANSITIONS[$current][$target] ?? []) : [];

        if ($actions === []) {
            return;
        }

        if ($this->dryRun) {
            OwLogger::addVisibleLog($this->logger, "DRY RUN — order $orderNumber: Minimax $minimaxStatus, would move $current → $target.");

            return;
        }

        $this->transition($order, $actions);

        ++$this->updated;
        OwLogger::addVisibleLog($this->logger, "Order $orderNumber: Minimax $minimaxStatus — moved $current → $target.");
    }

    /** @param list<string> $actions */
    private function transition(OrderEntity $order, array $actions): void
    {
        foreach ($actions as $action) {
            $this->stateMachineRegistry->transition(
                new Transition('order', $order->getId(), $action, 'stateId'),
                $this->context,
            );
        }
    }

    /**
     * Merge into the order's custom fields; the existing ones are kept so
     * another plugin's keys are never dropped.
     *
     * @param array<string, mixed> $fields
     */
    private function writeCustomFields(OrderEntity $order, array $fields): void
    {
        $this->orderRepository->update([[
            'id'           => $order->getId(),
            'customFields' => array_merge($order->getCustomFields() ?? [], $fields),
        ]], $this->context);
    }
}
