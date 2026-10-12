<?php declare(strict_types=1);

namespace OptiwebTheme\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * The goods in the cart are worth less than the configured minimum order value.
 *
 * A warning (not an error) so the storefront shows it in the calm yellow style, but
 * it blocks the order: Shopware refuses to place an order while any error in the
 * cart returns true from blockOrder().
 *
 * Shown as the flash message `checkout.sivanka-min-order-value`, with the already
 * formatted amounts as %minValue% and %missing%.
 */
class MinimumOrderValueError extends Error
{
    public const KEY = 'sivanka-min-order-value';

    public function __construct(
        private readonly string $minValue,
        private readonly string $missing,
    ) {
        parent::__construct();
    }

    public function getId(): string
    {
        return self::KEY;
    }

    public function getMessageKey(): string
    {
        return self::KEY;
    }

    public function getLevel(): int
    {
        return self::LEVEL_WARNING;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    /**
     * Not stored with the cart: the validator recomputes it on every calculation, and a
     * persisted copy would keep warning after the customer has already added enough.
     */
    public function isPersistent(): bool
    {
        return false;
    }

    /** @return array{minValue: string, missing: string} */
    public function getParameters(): array
    {
        return [
            'minValue' => $this->minValue,
            'missing'  => $this->missing,
        ];
    }
}
