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
 * Carries the Minimax order status back onto the Shopware order.
 *
 * Minimax "Potrjeno" (P) moves the order to "In progress", "Zaključeno" (Z) to
 * "Done" — through the regular state transitions, so the customer gets the same
 * status mails as for a change made in the admin. Only ever forwards: an order is
 * never moved back, and a draft (O) or invalidated (R) Minimax order changes nothing.
 *
 * Only orders this plugin exported (status "sent", with a Minimax id) that are
 * still open or in progress are looked at.
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
        $criteria->addFilter(new EqualsAnyFilter('stateMachineState.technicalName', array_keys(self::TRANSITIONS)));
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
        $orderNumber = (string) $order->getOrderNumber();
        $minimaxId   = (int) ($order->getCustomFields()[GlobalVariables::CUSTOM_FIELD_OPTIWEB_KEY] ?? 0);
        if ($minimaxId <= 0) {
            return;
        }

        $minimaxStatus = $this->minimaxClient->getOrderStatus($minimaxId);
        $target        = self::STATE_FOR_MINIMAX_STATUS[$minimaxStatus] ?? null;
        $current       = (string) $order->getStateMachineState()?->getTechnicalName();
        $actions       = $target !== null ? (self::TRANSITIONS[$current][$target] ?? []) : [];

        if ($actions === []) {
            return;
        }

        if ($this->dryRun) {
            OwLogger::addVisibleLog($this->logger, "DRY RUN — order $orderNumber: Minimax $minimaxStatus, would move $current → $target.");

            return;
        }

        foreach ($actions as $action) {
            $this->stateMachineRegistry->transition(
                new Transition('order', $order->getId(), $action, 'stateId'),
                $this->context,
            );
        }

        ++$this->updated;
        OwLogger::addVisibleLog($this->logger, "Order $orderNumber: Minimax $minimaxStatus — moved $current → $target.");
    }
}
