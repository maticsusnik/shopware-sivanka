<?php declare(strict_types=1);

namespace OptiwebTheme\Storefront\Subscriber;

use Doctrine\DBAL\Connection;
use OptiwebTheme\Core\Checkout\UpnQr\UpnQrService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Events\FlowSendMailActionEvent;
use Shopware\Core\Content\MailTemplate\MailTemplateTypes;
use Shopware\Core\Framework\Event\OrderAware;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Attaches the UPN QR (PNG) to the order confirmation e-mail of a bank-transfer order, so the
 * customer can scan it from the mail as well as from the thank-you page.
 *
 * Hooks the flow "send mail" action just before the mail is rendered and adds a binary
 * attachment to its data (`binAttachments`, the format core's MailFactory takes). Only the
 * order confirmation template gets it — a shop owner copy or any other mail sent by the same
 * flow is left alone. An attachment rather than an image in the HTML: Gmail and Outlook do not
 * show inline data-URI images, and a file opens on any device.
 *
 * Whether there is a QR at all is `UpnQrService::forOrder()`'s call (bank transfer, euros,
 * feature on, config complete), the same as on the thank-you page. When it is attached, the
 * template variable `owUpnQrAttached` is true, for a sentence in the mail text about it.
 */
class UpnQrMailSubscriber implements EventSubscriberInterface
{
    private ?string $orderConfirmationTypeId = null;

    /**
     * @internal
     */
    public function __construct(
        private readonly UpnQrService $upnQrService,
        private readonly Connection $connection,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FlowSendMailActionEvent::class => 'onFlowSendMail',
        ];
    }

    public function onFlowSendMail(FlowSendMailActionEvent $event): void
    {
        if ($event->getMailTemplate()->getMailTemplateTypeId() !== $this->orderConfirmationTypeId()) {
            return;
        }

        $order = $event->getStorableFlow()->getData(OrderAware::ORDER);
        if (!$order instanceof OrderEntity) {
            return;
        }

        // The PNG needs GD. Without it the mail still goes out, just without the attachment;
        // the thank-you page (SVG) does not depend on it.
        if (!\extension_loaded('gd')) {
            return;
        }

        $upnQr = $this->upnQrService->forOrder($order);
        if ($upnQr === null) {
            return;
        }

        $dataBag = $event->getDataBag();
        $attachments = $dataBag->get('binAttachments');

        $dataBag->set('binAttachments', [
            ...(\is_array($attachments) ? $attachments : []),
            [
                'content' => $this->upnQrService->renderPng($upnQr),
                'fileName' => \sprintf('UPN-QR-%s.png', $order->getOrderNumber()),
                'mimeType' => 'image/png',
            ],
        ]);

        // Core builds the template variables from the flow data after this event, so the mail
        // template can mention the attachment only when there is one:
        // {% if owUpnQrAttached ?? false %}…{% endif %}
        $event->getStorableFlow()->setData('owUpnQrAttached', true);
    }

    private function orderConfirmationTypeId(): ?string
    {
        if ($this->orderConfirmationTypeId === null) {
            $id = $this->connection->fetchOne(
                'SELECT LOWER(HEX(id)) FROM mail_template_type WHERE technical_name = :name',
                ['name' => MailTemplateTypes::MAILTYPE_ORDER_CONFIRM],
            );

            $this->orderConfirmationTypeId = \is_string($id) ? $id : null;
        }

        return $this->orderConfirmationTypeId;
    }
}
