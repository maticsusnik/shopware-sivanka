<?php declare(strict_types=1);

namespace OptiwebProductEnquiry;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class OptiwebProductEnquiry extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);

        $connection->executeStatement('DROP TABLE IF EXISTS `product_enquiry`');

        $connection->executeStatement(
            'DELETE FROM `mail_template_type` WHERE `technical_name` = :name',
            ['name' => 'product_enquiry_form']
        );
    }
}
