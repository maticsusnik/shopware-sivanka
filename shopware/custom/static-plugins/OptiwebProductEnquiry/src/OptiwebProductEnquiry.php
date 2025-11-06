<?php declare(strict_types=1);

namespace OptiwebProductEnquiry;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class OptiwebProductEnquiry extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        
        // Plugin installation logic can be added here if needed
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        
        // Plugin uninstallation logic can be added here if needed
    }
}
