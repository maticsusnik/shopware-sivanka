<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Rewrites the enquiry notification so it lists every product, not just the first.
 *
 * `enquiryLines` is handed to the template by the controller for both flavours of
 * enquiry — a product-detail enquiry simply has one line — so the template no
 * longer has to branch on where the enquiry came from.
 *
 * This overwrites the template's content unconditionally. That is safe here
 * because the template has never been edited in the admin on this shop; if it
 * ever is, re-running this migration would discard those edits.
 */
class Migration1748000005UpdateMailTemplateForMultipleProducts extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1748000005;
    }

    public function update(Connection $connection): void
    {
        $typeId = $connection->fetchOne(
            'SELECT id FROM mail_template_type WHERE technical_name = :name',
            ['name' => 'product_enquiry_form']
        );

        if (!$typeId) {
            return;
        }

        $templateId = $connection->fetchOne(
            'SELECT id FROM mail_template WHERE mail_template_type_id = :typeId LIMIT 1',
            ['typeId' => $typeId]
        );

        if (!$templateId) {
            return;
        }

        $this->writeTranslation(
            $connection,
            $templateId,
            Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            $this->html('New Product Enquiry', 'Customer Information', 'Products', 'Message', 'Name', 'Email', 'Phone', 'Product', 'Number', 'Variant', 'Quantity', 'Sent via'),
            $this->plain('New Product Enquiry', 'Name', 'Email', 'Phone', 'Products', 'Quantity', 'Message')
        );

        $deLanguageId = $this->languageId($connection, 'sl-SI') ?? $this->languageId($connection, 'de-DE');

        if ($deLanguageId !== null) {
            $this->writeTranslation(
                $connection,
                $templateId,
                $deLanguageId,
                $this->html('Novo povpraševanje o izdelku', 'Podatki o stranki', 'Izdelki', 'Sporočilo', 'Ime', 'E-pošta', 'Telefon', 'Izdelek', 'Številka', 'Varianta', 'Količina', 'Poslano prek'),
                $this->plain('Novo povpraševanje o izdelku', 'Ime', 'E-pošta', 'Telefon', 'Izdelki', 'Količina', 'Sporočilo')
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function writeTranslation(Connection $connection, string $templateId, string $languageId, string $html, string $plain): void
    {
        $connection->executeStatement(
            'UPDATE mail_template_translation
             SET content_html = :html, content_plain = :plain, updated_at = :now
             WHERE mail_template_id = :templateId AND language_id = :languageId',
            [
                'html'       => $html,
                'plain'      => $plain,
                'now'        => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'templateId' => $templateId,
                'languageId' => $languageId,
            ]
        );
    }

    private function languageId(Connection $connection, string $locale): ?string
    {
        $id = $connection->fetchOne(
            'SELECT l.id FROM language l
             JOIN locale lo ON lo.id = l.locale_id
             WHERE lo.code = :locale LIMIT 1',
            ['locale' => $locale]
        );

        return $id ?: null;
    }

    private function html(
        string $heading, string $customerHeading, string $productsHeading, string $messageHeading,
        string $name, string $email, string $phone,
        string $product, string $number, string $variant, string $quantity, string $sentVia,
    ): string {
        return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px;">
  <h2>{$heading}</h2>
  <h3>{$customerHeading}</h3>
  <p><strong>{$name}:</strong> {{ contactFormData.firstName }} {{ contactFormData.lastName }}<br>
  <strong>{$email}:</strong> {{ contactFormData.email }}<br>
  <strong>{$phone}:</strong> {{ contactFormData.phone ?: 'N/A' }}</p>
  <h3>{$productsHeading} ({{ enquiryLines|length }})</h3>
  <table cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse;width:100%">
    <tr style="background:#f5f5f5;text-align:left">
      <th>{$product}</th><th>{$number}</th><th>{$variant}</th><th style="text-align:right">{$quantity}</th>
    </tr>
    {% for line in enquiryLines %}
    <tr style="border-bottom:1px solid #eee">
      <td>{{ line.productName }}</td>
      <td>{{ line.productNumber }}</td>
      <td>{{ line.productOption ?: '-' }}</td>
      <td style="text-align:right">{{ line.quantity }}</td>
    </tr>
    {% endfor %}
  </table>
  {% if contactFormData.comment %}
  <h3>{$messageHeading}</h3>
  <p>{{ contactFormData.comment|nl2br }}</p>
  {% endif %}
  <hr>
  <p style="color:#999;font-size:12px">{$sentVia} {{ salesChannel.name }}</p>
</div>
HTML;
    }

    private function plain(
        string $heading, string $name, string $email, string $phone,
        string $productsHeading, string $quantity, string $messageHeading,
    ): string {
        return <<<PLAIN
{$heading}

{$name}: {{ contactFormData.firstName }} {{ contactFormData.lastName }}
{$email}: {{ contactFormData.email }}
{$phone}: {{ contactFormData.phone ?: 'N/A' }}

{$productsHeading} ({{ enquiryLines|length }}):
{% for line in enquiryLines %}
- {{ line.productName }} ({{ line.productNumber }}){% if line.productOption %} / {{ line.productOption }}{% endif %} — {$quantity}: {{ line.quantity }}
{% endfor %}

{% if contactFormData.comment %}
{$messageHeading}:
{{ contactFormData.comment }}
{% endif %}
PLAIN;
    }
}
