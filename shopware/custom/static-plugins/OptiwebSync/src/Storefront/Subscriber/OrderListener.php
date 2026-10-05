<?php

declare(strict_types=1);

namespace OptiwebSync\Storefront\Subscriber;

use OptiwebSync\Helper\GlobalVariables;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderListener implements EventSubscriberInterface
{
    private EntityRepository $orderRepository;

    public function __construct(EntityRepository $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_WRITTEN_EVENT => "onOrderWritten"
        ];
    }

    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();

        $writeResults = $event->getWriteResults();
        if (count($writeResults) < 1) return;

        $eventName = $event->getName();
        if ($eventName !== OrderEvents::ORDER_WRITTEN_EVENT) return;

        foreach ($writeResults as $writeResult) {
            if ($writeResult->getOperation() !== EntityWriteResult::OPERATION_INSERT) continue;

            $status = GlobalVariables::STATUS_WAITING;

            $payload = $writeResult->getPayload();
            $pantheonKey = $payload['customFields'][GlobalVariables::CUSTOM_FIELD_OPTIWEB_STATUS] ?? null;
            if ($pantheonKey !== null) {
                $status = $pantheonKey;
            }

            $this->setCustomField($context, $payload["id"], [GlobalVariables::CUSTOM_FIELD_OPTIWEB_STATUS => $status]);

            // NOTE: this listener used to force the order's itemRounding to 4 decimals to undo the
            // 8-decimal rounding of the OptiwebPricing plugin. That plugin is not part of this
            // project, so the override only made every order render prices as "4,1000 €" on the
            // finish page, in the account order history and in order mails. The order now keeps the
            // rounding of its currency (2 decimals).
        }
    }

    private function setCustomField(Context $context, string $orderId, $customFieldData)
    {
        $customFieldSetData = [
            "id" => $orderId,
        ];

        foreach ($customFieldData as $customFieldKey => $customFieldValue) {
            $customFieldSetData["customFields"][$customFieldKey] = $customFieldValue;
        }

        $this->orderRepository->update([$customFieldSetData], $context);
    }

}
