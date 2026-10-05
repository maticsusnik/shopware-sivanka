<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1748000003CreateMailTemplate extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1748000003;
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

        $now = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        $templateId = $connection->fetchOne(
            'SELECT id FROM mail_template WHERE mail_template_type_id = :typeId',
            ['typeId' => $typeId]
        );

        if (!$templateId) {
            $templateId = Uuid::randomBytes();

            $connection->executeStatement(
                'INSERT INTO `mail_template` (`id`, `mail_template_type_id`, `system_default`, `created_at`)
                 VALUES (:id, :typeId, 1, :createdAt)',
                [
                    'id'        => $templateId,
                    'typeId'    => $typeId,
                    'createdAt' => $now,
                ]
            );
        }

        $enLanguageId = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);

        $htmlEn = '<div style="font-family: Arial, sans-serif; max-width: 600px;">
  <h2>New Product Enquiry</h2>
  <h3>Customer Information</h3>
  <p><strong>Name:</strong> {{ contactFormData.firstName }} {{ contactFormData.lastName }}<br>
  <strong>Email:</strong> {{ contactFormData.email }}<br>
  <strong>Phone:</strong> {{ contactFormData.phone ?: \'N/A\' }}</p>
  <h3>Product Information</h3>
  <p><strong>Product:</strong> {{ product.translated.name }}<br>
  <strong>Number:</strong> {{ contactFormData.product_number }}<br>
  <strong>Variant:</strong> {{ contactFormData.product_option ?: \'N/A\' }}<br>
  <strong>Quantity:</strong> {{ contactFormData.productQty }}</p>
  {% if contactFormData.comment %}
  <h3>Message</h3>
  <p>{{ contactFormData.comment|nl2br }}</p>
  {% endif %}
  <hr>
  <p style="color:#999;font-size:12px">Sent via {{ salesChannel.name }}</p>
</div>';

        $plainEn = 'New Product Enquiry

Customer Information
Name: {{ contactFormData.firstName }} {{ contactFormData.lastName }}
Email: {{ contactFormData.email }}
Phone: {{ contactFormData.phone ?: \'N/A\' }}

Product Information
Product: {{ product.translated.name }}
Number: {{ contactFormData.product_number }}
Variant: {{ contactFormData.product_option ?: \'N/A\' }}
Quantity: {{ contactFormData.productQty }}

{% if contactFormData.comment %}
Message:
{{ contactFormData.comment }}
{% endif %}

Sent via {{ salesChannel.name }}';

        $existingEnTranslation = $connection->fetchOne(
            'SELECT 1 FROM `mail_template_translation`
             WHERE `mail_template_id` = :templateId AND `language_id` = :languageId',
            ['templateId' => $templateId, 'languageId' => $enLanguageId]
        );

        if (!$existingEnTranslation) {
            $connection->executeStatement(
                'INSERT INTO `mail_template_translation`
                 (`mail_template_id`, `language_id`, `subject`, `description`, `sender_name`, `content_html`, `content_plain`, `created_at`)
                 VALUES (:templateId, :languageId, :subject, :description, :senderName, :html, :plain, :createdAt)',
                [
                    'templateId'  => $templateId,
                    'languageId'  => $enLanguageId,
                    'subject'     => 'Product Enquiry',
                    'description' => 'Admin notification for new product enquiry submissions',
                    'senderName'  => '{{ salesChannel.name }}',
                    'html'        => $htmlEn,
                    'plain'       => $plainEn,
                    'createdAt'   => $now,
                ]
            );
        }

        // Slovenian translation — fetch sl-SI language id
        $slLanguageId = $connection->fetchOne(
            "SELECT l.id FROM language l
             INNER JOIN locale lo ON l.locale_id = lo.id
             WHERE lo.code = 'sl-SI'
             LIMIT 1"
        );

        if ($slLanguageId) {
            $existingSlTranslation = $connection->fetchOne(
                'SELECT 1 FROM `mail_template_translation`
                 WHERE `mail_template_id` = :templateId AND `language_id` = :languageId',
                ['templateId' => $templateId, 'languageId' => $slLanguageId]
            );

            if (!$existingSlTranslation) {
                $htmlSl = '<div style="font-family: Arial, sans-serif; max-width: 600px;">
  <h2>Novo povpraševanje o izdelku</h2>
  <h3>Podatki stranke</h3>
  <p><strong>Ime:</strong> {{ contactFormData.firstName }} {{ contactFormData.lastName }}<br>
  <strong>E-pošta:</strong> {{ contactFormData.email }}<br>
  <strong>Telefon:</strong> {{ contactFormData.phone ?: \'N/A\' }}</p>
  <h3>Podatki izdelka</h3>
  <p><strong>Izdelek:</strong> {{ product.translated.name }}<br>
  <strong>Številka:</strong> {{ contactFormData.product_number }}<br>
  <strong>Varianta:</strong> {{ contactFormData.product_option ?: \'N/A\' }}<br>
  <strong>Količina:</strong> {{ contactFormData.productQty }}</p>
  {% if contactFormData.comment %}
  <h3>Sporočilo</h3>
  <p>{{ contactFormData.comment|nl2br }}</p>
  {% endif %}
  <hr>
  <p style="color:#999;font-size:12px">Poslano prek {{ salesChannel.name }}</p>
</div>';

                $plainSl = 'Novo povpraševanje o izdelku

Podatki stranke
Ime: {{ contactFormData.firstName }} {{ contactFormData.lastName }}
E-pošta: {{ contactFormData.email }}
Telefon: {{ contactFormData.phone ?: \'N/A\' }}

Podatki izdelka
Izdelek: {{ product.translated.name }}
Številka: {{ contactFormData.product_number }}
Varianta: {{ contactFormData.product_option ?: \'N/A\' }}
Količina: {{ contactFormData.productQty }}

{% if contactFormData.comment %}
Sporočilo:
{{ contactFormData.comment }}
{% endif %}

Poslano prek {{ salesChannel.name }}';

                $connection->executeStatement(
                    'INSERT INTO `mail_template_translation`
                     (`mail_template_id`, `language_id`, `subject`, `description`, `sender_name`, `content_html`, `content_plain`, `created_at`)
                     VALUES (:templateId, :languageId, :subject, :description, :senderName, :html, :plain, :createdAt)',
                    [
                        'templateId'  => $templateId,
                        'languageId'  => $slLanguageId,
                        'subject'     => 'Povpraševanje po izdelku',
                        'description' => 'Obvestilo adminu o novem povpraševanju o izdelku',
                        'senderName'  => '{{ salesChannel.name }}',
                        'html'        => $htmlSl,
                        'plain'       => $plainSl,
                        'createdAt'   => $now,
                    ]
                );
            }
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
