<?php declare(strict_types=1);

namespace OptiwebTheme\Storefront\Subscriber;

use OptiwebTheme\Core\Checkout\UpnQr\UpnQrService;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Puts the UPN QR of a bank-transfer order on the thank-you page, as the `owUpnQr` extension
 * (read in Twig as `page.extensions.owUpnQr`):
 *
 *   svg        inline SVG of the code
 *   recipient  name, street, city
 *   iban       grouped in fours
 *   reference  model apart (SI00 10023)
 *   amount     float, for the currency filter
 *   purpose, dueDate
 *
 * Absent whenever `UpnQrService::forOrder()` gives no QR (not bank transfer, feature off,
 * config incomplete), so the template only checks for the extension.
 */
class CheckoutFinishPageSubscriber implements EventSubscriberInterface
{
    /**
     * @internal
     */
    public function __construct(private readonly UpnQrService $upnQrService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutFinishPageLoadedEvent::class => 'onFinishPageLoaded',
        ];
    }

    public function onFinishPageLoaded(CheckoutFinishPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $upnQr = $this->upnQrService->forOrder($page->getOrder(), $event->getSalesChannelContext()->getSalesChannelId());

        if ($upnQr === null) {
            return;
        }

        $page->addExtension('owUpnQr', new ArrayStruct([
            'svg' => $this->upnQrService->renderSvg($upnQr),
            'recipient' => [
                'name' => $upnQr->recipientName,
                'street' => $upnQr->recipientStreet,
                'city' => $upnQr->recipientCity,
            ],
            'iban' => $upnQr->formattedIban(),
            'reference' => $upnQr->formattedReference(),
            'amount' => $upnQr->amountCents / 100,
            'purpose' => $upnQr->purpose,
            'dueDate' => $upnQr->dueDate,
        ]));
    }
}
