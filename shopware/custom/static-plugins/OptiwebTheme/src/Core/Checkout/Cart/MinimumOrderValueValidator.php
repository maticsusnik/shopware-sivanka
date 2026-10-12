<?php declare(strict_types=1);

namespace OptiwebTheme\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Blocks checkout until the goods in the cart reach the minimum order value set in
 * the plugin config (OptiwebTheme.config.minOrderValue, per sales channel; empty or
 * 0 switches it off).
 *
 * "Goods" is the cart's position price — the subtotal of all line items including
 * discounts, without shipping — so it is the same figure the customer sees as the
 * subtotal in the cart summary. The minimum is entered in the default currency and
 * converted with the currency factor.
 */
class MinimumOrderValueValidator implements CartValidatorInterface
{
    public const CONFIG_KEY = 'OptiwebTheme.config.minOrderValue';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly CurrencyFormatter $currencyFormatter,
    ) {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        if ($cart->getLineItems()->count() === 0) {
            return;
        }

        $minimum = $this->systemConfigService->getFloat(self::CONFIG_KEY, $context->getSalesChannelId())
            * $context->getCurrency()->getFactor();

        if ($minimum <= 0) {
            return;
        }

        $goods = $cart->getPrice()->getPositionPrice();

        // Compare in cents, so 9.999999 from float maths never blocks a 10,00 € cart.
        if (round($goods, 2) >= round($minimum, 2)) {
            return;
        }

        $errors->add(new MinimumOrderValueError(
            $this->format($minimum, $context),
            $this->format($minimum - $goods, $context),
        ));
    }

    private function format(float $amount, SalesChannelContext $context): string
    {
        return $this->currencyFormatter->formatCurrencyByLanguage(
            $amount,
            $context->getCurrency()->getIsoCode(),
            $context->getLanguageId(),
            $context->getContext()
        );
    }
}
