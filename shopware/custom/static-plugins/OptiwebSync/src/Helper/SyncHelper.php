<?php

namespace OptiwebSync\Helper;

use Doctrine\DBAL\Connection;
use Shopware\Core\Kernel;

class SyncHelper
{
    public static function getConnection(): Connection
    {
        return Kernel::getConnection();
    }

}
