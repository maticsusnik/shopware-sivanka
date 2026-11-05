<?php declare(strict_types=1);
/*
 * LOCAL ONLY. Delete variant parents whose variants a merge moved into another family:
 *
 *   php delete-emptied-parents.php <delete-emptied-parents.json> [--apply]
 *
 * A parent is deleted only if it is a pipeline parent (number parent_…) and has no variants left: deleting a
 * parent deletes its children, so anything else is refused. Through the DAL. On prod: Admin, same check.
 */

use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\DbalKernelPluginLoader;
use Shopware\Core\Kernel;
use Symfony\Component\Dotenv\Dotenv;

$root = '/var/www/html';
$classLoader = require $root . '/vendor/autoload.php';
(new Dotenv())->usePutenv()->bootEnv($root . '/.env');

$items = json_decode((string) file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
$apply = in_array('--apply', $argv, true);

$kernel = KernelFactory::create(environment: 'dev', debug: false, classLoader: $classLoader,
    pluginLoader: new DbalKernelPluginLoader($classLoader, null, Kernel::getConnection()));
$kernel->boot();
$context = Context::createDefaultContext();
$products = $kernel->getContainer()->get('product.repository');

$ids = array_column($items, 'id');
$found = $ids === [] ? [] : $products->search((new Criteria($ids))->addAssociation('children'), $context)->getEntities();
$delete = [];
foreach ($items as $item) {
    $p = $found->get($item['id']);
    if ($p === null) {
        printf("  %s: already gone\n", $item['number']);
    } elseif (!str_starts_with((string) $p->getProductNumber(), 'parent_') || $p->getChildren()?->count()) {
        printf("  %s: REFUSED (not a pipeline parent or still has %d variants)\n", $item['number'], $p->getChildren()?->count() ?? 0);
    } else {
        $delete[] = ['id' => $item['id']];
    }
}
printf("emptied parents to delete: %d\n", count($delete));
if ($apply && $delete !== []) {
    $products->delete($delete, $context);
    printf("deleted %d\n", count($delete));
}
