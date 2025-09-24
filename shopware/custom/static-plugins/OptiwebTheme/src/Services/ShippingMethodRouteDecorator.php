<?php
declare(strict_types=1);

namespace OptiwebTheme\Services;

use Shopware\Core\Checkout\Cart\Delivery\DeliveryProcessor;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Shipping\SalesChannel\ShippingMethodRoute;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Shipping\SalesChannel\ShippingMethodRouteResponse;
use Shopware\Core\Framework\Rule\RuleIdMatcher;
use Shopware\Core\Framework\Script\Execution\ScriptExecutor;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ShippingMethodRouteDecorator extends ShippingMethodRoute
{

    public function __construct(
        private ShippingMethodRoute $shippingMethodRoute,
        private CartService $cartService,
        private DeliveryCalculator $deliveryCalculator
    ) {
    }

    public function getDecorated(): ShippingMethodRoute
    {
        return $this->shippingMethodRoute;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria
    ): ShippingMethodRouteResponse {
        $criteria->addAssociation('prices');
        $result = $this->shippingMethodRoute->load($request, $context, $criteria);
        $originalResult = clone $result;
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $shippingMethods = $result->getShippingMethods()->filterByActiveRules($context);
        try {
            /** @var ShippingMethodEntity $shippingMethod */
            foreach ($shippingMethods as $shippingMethod) {
                $cartClone = clone $cart;
                $cartData = $cartClone->getData();
                $deliveries = $cartClone->getDeliveries();
                /** @var Delivery $delivery */
                foreach ($deliveries as $delivery) {
                    $delivery->setShippingCosts(new CalculatedPrice(0, 0, new CalculatedTaxCollection(), new TaxRuleCollection()));
                    $delivery->setShippingMethod($shippingMethod);
                }
                $cartData->set(DeliveryProcessor::buildKey($shippingMethod->getId()), $shippingMethod);
                $this->deliveryCalculator->calculate($cartData, $cartClone, $deliveries, $context);
                $shippingCost = $cartClone->getDeliveries()->getShippingCosts()->sum()->getTotalPrice();
                /** @phpstan-ignore-next-line */
                $shippingMethod->shippingPrice = $shippingCost;
            }
        } catch (\Error $exception) {
            return $originalResult;
        }

        return $result;
    }

}
