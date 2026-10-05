<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1748000001CreateProductEnquiryTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1748000001;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `product_enquiry` (
              `id` BINARY(16) NOT NULL,
              `sales_channel_id` BINARY(16) NOT NULL,
              `first_name` VARCHAR(255) NOT NULL,
              `last_name` VARCHAR(255) NOT NULL,
              `email` VARCHAR(255) NOT NULL,
              `phone` VARCHAR(50) NULL,
              `product_id` BINARY(16) NULL,
              `product_name` VARCHAR(255) NOT NULL,
              `product_number` VARCHAR(255) NOT NULL,
              `product_option` VARCHAR(255) NULL,
              `quantity` INT(11) NOT NULL DEFAULT 1,
              `message` LONGTEXT NULL,
              `status` VARCHAR(50) NOT NULL DEFAULT \'new\',
              `created_at` DATETIME(3) NOT NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`id`),
              KEY `idx_product_enquiry_status` (`status`),
              KEY `idx_product_enquiry_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
