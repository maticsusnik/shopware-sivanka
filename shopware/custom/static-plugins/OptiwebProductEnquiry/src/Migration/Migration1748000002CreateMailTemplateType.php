<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1748000002CreateMailTemplateType extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1748000002;
    }

    public function update(Connection $connection): void
    {
        $now = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        $typeId = $connection->fetchOne(
            'SELECT id FROM mail_template_type WHERE technical_name = :name',
            ['name' => 'product_enquiry_form']
        );

        if (!$typeId) {
            $typeId = Uuid::randomBytes();

            $connection->executeStatement(
                'INSERT INTO `mail_template_type` (`id`, `technical_name`, `available_entities`, `created_at`)
                 VALUES (:id, :name, :entities, :createdAt)',
                [
                    'id'        => $typeId,
                    'name'      => 'product_enquiry_form',
                    'entities'  => json_encode([
                        'salesChannel'    => 'sales_channel',
                        'product'         => 'product',
                        'contactFormData' => 'contact_form_data',
                        'enquiry'         => 'product_enquiry',
                    ]),
                    'createdAt' => $now,
                ]
            );
        }

        $languageId = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);

        $existingTranslation = $connection->fetchOne(
            'SELECT 1 FROM `mail_template_type_translation`
             WHERE `mail_template_type_id` = :typeId AND `language_id` = :languageId',
            ['typeId' => $typeId, 'languageId' => $languageId]
        );

        if (!$existingTranslation) {
            $connection->executeStatement(
                'INSERT INTO `mail_template_type_translation`
                 (`mail_template_type_id`, `language_id`, `name`, `created_at`)
                 VALUES (:typeId, :languageId, :name, :createdAt)',
                [
                    'typeId'     => $typeId,
                    'languageId' => $languageId,
                    'name'       => 'Product Enquiry Form',
                    'createdAt'  => $now,
                ]
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
