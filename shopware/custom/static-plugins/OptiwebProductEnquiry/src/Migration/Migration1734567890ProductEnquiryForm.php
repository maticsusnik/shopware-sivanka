<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Defaults;

class Migration1734567890ProductEnquiryForm extends MigrationStep
{
    private const MAILTYPE_PRODUCT_ENQUIRY_FORM = 'product_enquiry_form';

    public function getCreationTimestamp(): int
    {
        return 1734567890;
    }

    public function update(Connection $connection): void
    {
        $this->createMailTemplateType($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive if needed
    }

    private function createMailTemplateType(Connection $connection): void
    {
        $mailTemplateTypeId = Uuid::randomHex();
        $mailTemplateTypeIdBytes = Uuid::fromHexToBytes($mailTemplateTypeId);

        $connection->insert(
            'mail_template_type',
            [
                'id' => $mailTemplateTypeIdBytes,
                'technical_name' => self::MAILTYPE_PRODUCT_ENQUIRY_FORM,
                'available_entities' => json_encode([
                    'salesChannel' => 'sales_channel',
                    'product' => 'product',
                    'productManufacturer' => 'product_manufacturer',
                    'contactFormData' => 'contact_form_data'
                ]),
                'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );

        // Add English translation
        $connection->insert(
            'mail_template_type_translation',
            [
                'mail_template_type_id' => $mailTemplateTypeIdBytes,
                'name' => 'Product Enquiry Form',
                'language_id' => $this->getLanguageIdByLocale($connection, 'en-GB'),
                'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );

        // Add German translation
        $connection->insert(
            'mail_template_type_translation',
            [
                'mail_template_type_id' => $mailTemplateTypeIdBytes,
                'name' => 'Produktanfrage Formular',
                'language_id' => $this->getLanguageIdByLocale($connection, 'de-DE'),
                'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );
    }

    private function getLanguageIdByLocale(Connection $connection, string $locale): string
    {
        $sql = <<<SQL
        SELECT `language`.`id`
        FROM `language`
        INNER JOIN `locale` ON `locale`.`id` = `language`.`locale_id`
        WHERE `locale`.`code` = :code
        SQL;

        $languageId = $connection->executeQuery($sql, ['code' => $locale])->fetchOne();
        if (!$languageId) {
            throw new \RuntimeException(sprintf('Language for locale "%s" not found.', $locale));
        }

        return $languageId;
    }
}
