<?php
declare(strict_types=1);

namespace OptiwebTheme\Services;

use Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryProcessor;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Shipping\SalesChannel\AbstractShippingMethodRoute;
use Shopware\Core\Checkout\Shipping\SalesChannel\ShippingMethodRouteResponse;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Rule\RuleIdMatcher;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Calculates the shipping cost of every rule-available shipping method against the
 * current cart and exposes it to the storefront as the `optiwebShippingPrice` entity
 * extension (`shipping.extensions.optiwebShippingPrice.price`).
 */
class ShippingMethodRouteDecorator extends AbstractShippingMethodRoute
{
    public const EXTENSION_NAME = 'optiwebShippingPrice';

    public function __construct(
        private readonly AbstractShippingMethodRoute $shippingMethodRoute,
        private readonly CartService $cartService,
        private readonly DeliveryCalculator $deliveryCalculator,
        private readonly RuleIdMatcher $ruleIdMatcher,
    ) {
    }

    public function getDecorated(): AbstractShippingMethodRoute
    {
        return $this->shippingMethodRoute;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): ShippingMethodRouteResponse
    {
        $criteria->addAssociation('prices');
        $result = $this->shippingMethodRoute->load($request, $context, $criteria);
        $originalResult = clone $result;
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $shippingMethods = $this->ruleIdMatcher->filterCollection($result->getShippingMethods(), $context->getRuleIds());
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
                $shippingMethod->addArrayExtension(self::EXTENSION_NAME, ['price' => $shippingCost]);
            }
        } catch (\Error $exception) {
            return $originalResult;
        }

        return $result;
    }
}
