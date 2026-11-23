<?php declare(strict_types=1);

namespace OptiwebTheme\Core\Checkout\UpnQr;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Common\Version;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Builds the UPN QR for an order paid by bank transfer ("Predračun") and renders it.
 *
 * Everything about the recipient comes from the plugin config ("UPN QR" card): the
 * shop's company, address and IBAN, the reference model, the purpose code and text, and the
 * payment deadline in days. The order adds the amount, the order number (reference, purpose)
 * and the order date (deadline).
 *
 * No QR — `forOrder()` returns null — when the feature is off, the order is not paid by
 * bank transfer or not in euros, or the config is incomplete or invalid. An invalid config is
 * logged, so a wrong IBAN shows up in the log instead of silently in no customer's QR.
 *
 * The code is written as the standard asks: ISO-8859-2 (with its ECI marker), error
 * correction M, version 15.
 */
class UpnQrService
{
    /**
     * Sivanka's bank transfer is core's invoice handler, labelled "Predračun" in the shop
     * (core's own prepayment method stays inactive). Both count, so switching the method
     * over later doesn't silently drop the QR.
     */
    public const BANK_TRANSFER_TECHNICAL_NAMES = ['payment_invoicepayment', 'payment_prepayment'];

    private const CONFIG_PREFIX = 'OptiwebTheme.config.';
    private const CHARSET = 'ISO-8859-2';
    private const QR_VERSION = 15;
    private const DEFAULT_DUE_DAYS = 7;

    /**
     * @internal
     */
    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function forOrder(OrderEntity $order, ?string $salesChannelId = null): ?UpnQr
    {
        $salesChannelId ??= $order->getSalesChannelId();

        if (!$this->config('upnQrEnabled', $salesChannelId) || !$this->isBankTransfer($order)) {
            return null;
        }

        if ($order->getCurrency()?->getIsoCode() !== 'EUR') {
            return null;
        }

        $orderNumber = (string) $order->getOrderNumber();
        $model = strtoupper(trim((string) ($this->config('upnQrReferenceModel', $salesChannelId) ?: 'SI00')));
        // A reference carries digits (and at most two dashes for SI models); anything else
        // in an order number prefix would make it invalid.
        $referenceNumber = preg_replace('/[^0-9]/', '', $orderNumber) ?? '';

        $dueDays = (int) ($this->config('upnQrDueDays', $salesChannelId) ?? self::DEFAULT_DUE_DAYS);
        $orderDate = \DateTimeImmutable::createFromInterface($order->getOrderDateTime());

        $purpose = str_replace(
            '%orderNumber%',
            $orderNumber,
            (string) ($this->config('upnQrPurpose', $salesChannelId) ?: 'Naročilo %orderNumber%'),
        );

        try {
            return UpnQr::create(
                amountCents: (int) round($order->getAmountTotal() * 100),
                purposeCode: (string) ($this->config('upnQrPurposeCode', $salesChannelId) ?: 'GDSV'),
                purpose: $purpose,
                dueDate: $orderDate->modify(\sprintf('+%d days', max(0, $dueDays))),
                recipientIban: (string) $this->config('upnQrIban', $salesChannelId),
                recipientReference: $model . $referenceNumber,
                recipientName: (string) $this->config('upnQrRecipientName', $salesChannelId),
                recipientStreet: (string) $this->config('upnQrRecipientStreet', $salesChannelId),
                recipientCity: (string) $this->config('upnQrRecipientCity', $salesChannelId),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->logger->warning('UPN QR not generated for order {orderNumber}: {reason}', [
                'orderNumber' => $orderNumber,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function isBankTransfer(OrderEntity $order): bool
    {
        $paymentMethod = $order->getTransactions()?->last()?->getPaymentMethod();

        return $paymentMethod !== null
            && \in_array($paymentMethod->getTechnicalName(), self::BANK_TRANSFER_TECHNICAL_NAMES, true);
    }

    /**
     * Inline SVG markup (no XML declaration), scalable with CSS.
     */
    public function renderSvg(UpnQr $upnQr, int $size = 240): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 4), new SvgImageBackEnd()));

        $svg = $this->write($writer, $upnQr);

        return trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', $svg));
    }

    /**
     * PNG bytes (GD), for an e-mail attachment.
     */
    public function renderPng(UpnQr $upnQr, int $size = 600): string
    {
        return $this->write(new Writer(new GDLibRenderer($size, 4)), $upnQr);
    }

    private function write(Writer $writer, UpnQr $upnQr): string
    {
        return $writer->writeString(
            $upnQr->toPayload(),
            self::CHARSET,
            ErrorCorrectionLevel::M(),
            Version::getVersionForNumber(self::QR_VERSION),
        );
    }

    private function config(string $key, ?string $salesChannelId): mixed
    {
        return $this->systemConfig->get(self::CONFIG_PREFIX . $key, $salesChannelId);
    }
}
