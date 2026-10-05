<?php declare(strict_types=1);

namespace OptiwebTheme\Storefront\Subscriber;

use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CartLineItemAddedEvent implements EventSubscriberInterface
{
    private SystemConfigService $systemConfigService;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeLineItemAddedEvent::class => 'onBeforeLineItemAdded'
        ];
    }

    /**
     * This method is called before a line item is added to the cart.
     * It checks if the customer is logged in and part of B2B SC and throws an exception if not.
     */
    public function onBeforeLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $customer = $context->getCustomer();
        $b2bSc = $this->systemConfigService->get('OptiwebSync.config.customerB2BSubjectGroup') ?? null;

        // if (!$customer || $customer->getGroupId() != $b2bSc) {
        //     throw new AccessDeniedHttpException('You must be logged in to add products to the cart.');
        // }
    }
}