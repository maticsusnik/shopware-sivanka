<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Defaults;

class Migration1734567891ProductEnquiryMailTemplate extends MigrationStep
{
    private const MAILTYPE_PRODUCT_ENQUIRY_FORM = 'product_enquiry_form';

    public function getCreationTimestamp(): int
    {
        return 1734567891;
    }

    public function update(Connection $connection): void
    {
        $mailTemplateTypeId = $this->getMailTemplateTypeId($connection);
        
        if (!$mailTemplateTypeId) {
            // Mail template type should be created by previous migration
            // If it doesn't exist, skip template creation
            return;
        }

        $mailTemplateId = $this->getMailTemplateId($connection);
        $update = false;

        if (!$mailTemplateId) {
            $mailTemplateId = Uuid::randomBytes();
        } else {
            $update = true;
        }

        if (!is_string($mailTemplateId)) {
            return;
        }

        if ($update === true) {
            $connection->update(
                'mail_template',
                [
                    'updated_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ],
                ['id' => $mailTemplateId]
            );

            $connection->delete('mail_template_translation', ['mail_template_id' => $mailTemplateId]);
        } else {
            $connection->insert(
                'mail_template',
                [
                    'id' => $mailTemplateId,
                    'mail_template_type_id' => $mailTemplateTypeId,
                    'system_default' => 1,
                    'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]
            );
        }

        // Insert English translation
        $englishLanguageId = $this->getLanguageIdByLocale($connection, 'en-GB');
        if ($englishLanguageId) {
            $connection->insert(
                'mail_template_translation',
                [
                    'subject' => 'Product enquiry form',
                    'description' => 'Product enquiry form template for customer.',
                    'sender_name' => '{{ salesChannel.name }}',
                    'content_html' => $this->getRegistrationHtmlTemplateEn(),
                    'content_plain' => $this->getRegistrationPlainTemplateEn(),
                    'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    'mail_template_id' => $mailTemplateId,
                    'language_id' => $englishLanguageId,
                ]
            );
        }

        // Insert Slovenian translation if available
        $slovenianLanguageId = $this->getLanguageIdByLocale($connection, 'sl-SI');
        if ($slovenianLanguageId) {
            $connection->insert(
                'mail_template_translation',
                [
                    'subject' => 'Povpraševanje po izdelku',
                    'description' => 'Predloga obrazca za povpraševanje po izdelku za stranke.',
                    'sender_name' => '{{ salesChannel.name }}',
                    'content_html' => $this->getRegistrationHtmlTemplateSl(),
                    'content_plain' => $this->getRegistrationPlainTemplateSl(),
                    'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    'mail_template_id' => $mailTemplateId,
                    'language_id' => $slovenianLanguageId,
                ]
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive if needed
    }

    private function getMailTemplateTypeId(Connection $connection): ?string
    {
        $sql = <<<SQL
SELECT `mail_template_type`.`id`
FROM `mail_template_type`
WHERE `mail_template_type`.`technical_name` = :technical_name
SQL;

        $templateTypeId = $connection->executeQuery(
            $sql,
            [
                'technical_name' => self::MAILTYPE_PRODUCT_ENQUIRY_FORM,
            ]
        )->fetchOne();

        if ($templateTypeId) {
            return $templateTypeId;
        }

        return null;
    }

    private function getMailTemplateId(Connection $connection): ?string
    {
        $sql = <<<SQL
SELECT `mail_template`.`id`
FROM `mail_template` 
LEFT JOIN `mail_template_type` ON `mail_template`.`mail_template_type_id` = `mail_template_type`.`id`
WHERE `mail_template_type`.`technical_name` = :technical_name
SQL;

        $templateId = $connection->executeQuery(
            $sql,
            [
                'technical_name' => self::MAILTYPE_PRODUCT_ENQUIRY_FORM,
            ]
        )->fetchOne();

        if ($templateId) {
            return $templateId;
        }

        return null;
    }

    private function getLanguageIdByLocale(Connection $connection, string $locale): ?string
    {
        $sql = <<<SQL
SELECT `language`.`id`
FROM `language`
INNER JOIN `locale` ON `locale`.`id` = `language`.`locale_id`
WHERE `locale`.`code` = :code
SQL;

        $languageId = $connection->executeQuery($sql, ['code' => $locale])->fetchOne();
        
        return $languageId ?: null;
    }

    private function getRegistrationHtmlTemplateEn(): string
    {
        return '<div style="font-family:arial; font-size:12px;">
                    <h2>Product Enquiry</h2>
                    <p>
                        The following message was sent to you via the product enquiry form:
                    </p>
                    <hr/>
                    <p>
                        <strong>Contact Information:</strong><br/>
                        Name: {{ contactFormData.firstName }} {{ contactFormData.lastName }}<br/>
                        Email: {{ contactFormData.email }}<br/>
                        {% if contactFormData.phone %}Phone: {{ contactFormData.phone }}<br/>{% endif %}
                    </p>
                    <hr/>
                    <p>
                        <strong>Product Information:</strong><br/>
                        Product name: {{ product.translated.name }}<br/>
                        Product number: {{ contactFormData.product_number }}<br/>
                        {% if contactFormData.product_url %}Product URL: <a href="{{ contactFormData.product_url }}">{{ contactFormData.product_url }}</a><br/>{% endif %}
                        {% if contactFormData.productQty %}Quantity: {{ contactFormData.productQty }}<br/>{% endif %}
                        {% if contactFormData.product_option %}Variant: {{ contactFormData.product_option }}<br/>{% endif %}
                    </p>
                    {% if contactFormData.comment %}
                    <hr/>
                    <p>
                        <strong>Message:</strong><br/>
                        {{ contactFormData.comment }}
                    </p>
                    {% endif %}
                </div>';
    }

    private function getRegistrationPlainTemplateEn(): string
    {
        return 'Product Enquiry

The following message was sent to you via the product enquiry form:

Contact Information:
Name: {{ contactFormData.firstName }} {{ contactFormData.lastName }}
Email: {{ contactFormData.email }}
{% if contactFormData.phone %}Phone: {{ contactFormData.phone }}{% endif %}

Product Information:
Product name: {{ product.translated.name }}
Product number: {{ contactFormData.product_number }}
{% if contactFormData.product_url %}Product URL: {{ contactFormData.product_url }}{% endif %}
{% if contactFormData.productQty %}Quantity: {{ contactFormData.productQty }}{% endif %}
{% if contactFormData.product_option %}Variant: {{ contactFormData.product_option }}{% endif %}

{% if contactFormData.comment %}Message:
{{ contactFormData.comment }}{% endif %}';
    }

    private function getRegistrationHtmlTemplateDe(): string
    {
        return '<div style="font-family:arial; font-size:12px;">
                    <h2>Produktanfrage</h2>
                    <p>
                        Die folgende Nachricht wurde Ihnen über das Produktanfrageformular gesendet:
                    </p>
                    <hr/>
                    <p>
                        <strong>Kontaktinformationen:</strong><br/>
                        Name: {{ contactFormData.firstName }} {{ contactFormData.lastName }}<br/>
                        E-Mail: {{ contactFormData.email }}<br/>
                        {% if contactFormData.phone %}Telefon: {{ contactFormData.phone }}<br/>{% endif %}
                    </p>
                    <hr/>
                    <p>
                        <strong>Produktinformationen:</strong><br/>
                        Produktname: {{ product.translated.name }}<br/>
                        Produktnummer: {{ contactFormData.product_number }}<br/>
                        {% if contactFormData.product_url %}Produkt-URL: <a href="{{ contactFormData.product_url }}">{{ contactFormData.product_url }}</a><br/>{% endif %}
                        {% if contactFormData.productQty %}Menge: {{ contactFormData.productQty }}<br/>{% endif %}
                        {% if contactFormData.product_option %}Variante: {{ contactFormData.product_option }}<br/>{% endif %}
                    </p>
                    {% if contactFormData.comment %}
                    <hr/>
                    <p>
                        <strong>Nachricht:</strong><br/>
                        {{ contactFormData.comment }}
                    </p>
                    {% endif %}
                </div>';
    }

    private function getRegistrationPlainTemplateDe(): string
    {
        return 'Produktanfrage

Die folgende Nachricht wurde Ihnen über das Produktanfrageformular gesendet:

Kontaktinformationen:
Name: {{ contactFormData.firstName }} {{ contactFormData.lastName }}
E-Mail: {{ contactFormData.email }}
{% if contactFormData.phone %}Telefon: {{ contactFormData.phone }}{% endif %}

Produktinformationen:
Produktname: {{ product.translated.name }}
Produktnummer: {{ contactFormData.product_number }}
{% if contactFormData.product_url %}Produkt-URL: {{ contactFormData.product_url }}{% endif %}
{% if contactFormData.productQty %}Menge: {{ contactFormData.productQty }}{% endif %}
{% if contactFormData.product_option %}Variante: {{ contactFormData.product_option }}{% endif %}

{% if contactFormData.comment %}Nachricht:
{{ contactFormData.comment }}{% endif %}';
    }

    private function getRegistrationHtmlTemplateSl(): string
    {
        return '<div style="font-family:arial; font-size:12px;">
                    <h2>Povpraševanje po izdelku</h2>
                    <p>
                        Spodaj je sporočilo, ki ste ga prejeli prek obrazca za povpraševanje po izdelku:
                    </p>
                    <hr/>
                    <p>
                        <strong>Kontaktne informacije:</strong><br/>
                        Ime: {{ contactFormData.firstName }} {{ contactFormData.lastName }}<br/>
                        E-pošta: {{ contactFormData.email }}<br/>
                        {% if contactFormData.phone %}Telefon: {{ contactFormData.phone }}<br/>{% endif %}
                    </p>
                    <hr/>
                    <p>
                        <strong>Informacije o izdelku:</strong><br/>
                        Ime izdelka: {{ product.translated.name }}<br/>
                        Številka izdelka: {{ contactFormData.product_number }}<br/>
                        {% if contactFormData.product_url %}URL izdelka: <a href="{{ contactFormData.product_url }}">{{ contactFormData.product_url }}</a><br/>{% endif %}
                        {% if contactFormData.productQty %}Količina: {{ contactFormData.productQty }}<br/>{% endif %}
                        {% if contactFormData.product_option %}Varianta: {{ contactFormData.product_option }}<br/>{% endif %}
                    </p>
                    {% if contactFormData.comment %}
                    <hr/>
                    <p>
                        <strong>Sporočilo:</strong><br/>
                        {{ contactFormData.comment }}
                    </p>
                    {% endif %}
                </div>';
    }

    private function getRegistrationPlainTemplateSl(): string
    {
        return 'Povpraševanje po izdelku

Spodaj je sporočilo, ki ste ga prejeli prek obrazca za povpraševanje po izdelku:

Kontaktne informacije:
Ime: {{ contactFormData.firstName }} {{ contactFormData.lastName }}
E-pošta: {{ contactFormData.email }}
{% if contactFormData.phone %}Telefon: {{ contactFormData.phone }}{% endif %}

Informacije o izdelku:
Ime izdelka: {{ product.translated.name }}
Številka izdelka: {{ contactFormData.product_number }}
{% if contactFormData.product_url %}URL izdelka: {{ contactFormData.product_url }}{% endif %}
{% if contactFormData.productQty %}Količina: {{ contactFormData.productQty }}{% endif %}
{% if contactFormData.product_option %}Varianta: {{ contactFormData.product_option }}{% endif %}

{% if contactFormData.comment %}Sporočilo:
{{ contactFormData.comment }}{% endif %}';
    }
}

