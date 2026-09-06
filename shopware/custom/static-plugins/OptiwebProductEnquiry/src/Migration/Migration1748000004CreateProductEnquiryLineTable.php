<?php declare(strict_types=1);

namespace OptiwebProductEnquiry\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Turns an enquiry into a header + line items so one submission can cover several
 * products (the wishlist enquiry).
 *
 * The single-product columns on `product_enquiry` are deliberately kept and go on
 * being written with the *first* line: the admin list and the existing mail
 * template both read them, and dropping them would break historical rows.
 * `product_count` tells the two apart at a glance.
 */
class Migration1748000004CreateProductEnquiryLineTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1748000004;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `product_enquiry_line` (
              `id` BINARY(16) NOT NULL,
              `product_enquiry_id` BINARY(16) NOT NULL,
              `product_id` BINARY(16) NULL,
              `product_name` VARCHAR(255) NOT NULL,
              `product_number` VARCHAR(255) NOT NULL,
              `product_option` VARCHAR(255) NULL,
              `quantity` INT(11) NOT NULL DEFAULT 1,
              `position` INT(11) NOT NULL DEFAULT 0,
              `created_at` DATETIME(3) NOT NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`id`),
              KEY `idx_product_enquiry_line_enquiry` (`product_enquiry_id`),
              CONSTRAINT `fk.product_enquiry_line.product_enquiry_id`
                FOREIGN KEY (`product_enquiry_id`) REFERENCES `product_enquiry` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        if (!$this->columnExists($connection, 'product_enquiry', 'product_count')) {
            $connection->executeStatement(
                'ALTER TABLE `product_enquiry` ADD COLUMN `product_count` INT(11) NOT NULL DEFAULT 1'
            );
        }

        $this->backfillLines($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    /**
     * Give every pre-existing single-product enquiry the line it would have been
     * written with today, so the admin and the mail template can read lines
     * unconditionally instead of branching on "old row or new row".
     */
    private function backfillLines(Connection $connection): void
    {
        $connection->executeStatement('
            INSERT INTO `product_enquiry_line`
                (`id`, `product_enquiry_id`, `product_id`, `product_name`, `product_number`,
                 `product_option`, `quantity`, `position`, `created_at`)
            SELECT
                UNHEX(REPLACE(UUID(), "-", "")),
                e.`id`,
                e.`product_id`,
                e.`product_name`,
                e.`product_number`,
                e.`product_option`,
                e.`quantity`,
                0,
                e.`created_at`
            FROM `product_enquiry` e
            LEFT JOIN `product_enquiry_line` l ON l.`product_enquiry_id` = e.`id`
            WHERE l.`id` IS NULL
        ');
    }
}
