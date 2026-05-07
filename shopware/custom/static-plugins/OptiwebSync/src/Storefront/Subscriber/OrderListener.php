<?php

declare(strict_types=1);

namespace OptiwebSync\Storefront\Subscriber;

use OptiwebSync\Helper\GlobalVariables;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
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

            $itemRounding = $payload["itemRounding"] ?? null;
            if($itemRounding){
                $itemRounding->setDecimals(4);
                /** OptiwebPricing plugin sets rounding to 8 decimals at a certain point. This call restores the rounding to 4 deci */
                $this->setItemRounding($writeResult->getPayload()["id"], $itemRounding, $context);
            }
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

    public function setItemRounding(string $orderId, CashRoundingConfig $itemRounding, $context): void
    {
        try {
            $this->orderRepository->update([
                [
                    "id" => $orderId,
                    "itemRounding" => $itemRounding->jsonSerialize(),
                ],
            ], $context);
        } catch (\Exception $error) {
            return;
        }
    }

}
