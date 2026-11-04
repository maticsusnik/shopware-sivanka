<?php declare(strict_types=1);
/*
 * LOCAL ONLY. Apply the part of the catalogue fixes that Import/Export cannot do:
 *
 *   php apply-fixes.php <fixes-manual.json> [--apply]
 *
 * - remove_property: deletes one product -> property option link (a wrong value, e.g. Barva: Zlata on an
 *   ST.ZLATO product). The correct value comes in through the normal import (02-product-properties.csv).
 * - short_description: sets customFields.shortDescription (Slovene, the system language) to the corrected text.
 * Through the DAL, so indexes and caches follow. Without --apply it only prints what it would do.
 * On prod the same list is done by hand in the Admin (see import/README.md).
 */

use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\Context;
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
$container = $kernel->getContainer();
$context = Context::createDefaultContext();
$products = $container->get('product.repository');
$links = $container->get('product_property.repository');

$remove = array_values(array_filter($items, static fn ($i) => $i['kind'] === 'remove_property'));
$short = array_values(array_filter($items, static fn ($i) => $i['kind'] === 'short_description'));
printf("property links to remove: %d, short descriptions to correct: %d\n", count($remove), count($short));
foreach ($short as $s) {
    printf("  %s: %s\n", $s['number'], mb_substr($s['value'], 0, 90));
}
if (!$apply) {
    echo "dry run, nothing changed (add --apply)\n";
    exit(0);
}

if ($remove !== []) {
    $links->delete(array_map(static fn ($r) => ['productId' => $r['product_id'], 'optionId' => $r['option_id']], $remove), $context);
}
if ($short !== []) {
    // customFields on a translated entity: the DAL merges the given keys into the existing JSON
    $products->update(array_map(static fn ($s) => ['id' => $s['product_id'], 'customFields' => ['shortDescription' => $s['value']]], $short), $context);
}
printf("removed %d links, corrected %d short descriptions\n", count($remove), count($short));
