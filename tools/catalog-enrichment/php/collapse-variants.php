<?php declare(strict_types=1);
/*
 * LOCAL ONLY. Turn variant families back into the plain Minimax product they came from.
 *
 *   php collapse-variants.php <plan.json> [--apply]
 *
 * plan.json: {"split": ["<parent number>", ...], "delete": ["<variant number>", ...]}
 *  1. every listed parent goes through OptiwebTheme's VariantSplitter with deleteParent=false (the same
 *     code as the Admin "Split variants" action): its variants become standalone, the parent becomes a
 *     simple product (configurator settings and listing config removed), keeping its number and stock;
 *  2. the listed former variants (numbers Minimax doesn't know) are deleted.
 * Without --apply it only prints what it would do. On prod the same two steps are done in the Admin.
 */

use OptiwebTheme\Controller\Api\VariantBuilderController;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\DbalKernelPluginLoader;
use Shopware\Core\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

$root = '/var/www/html';
$classLoader = require $root . '/vendor/autoload.php';
(new Dotenv())->usePutenv()->bootEnv($root . '/.env');

[$plan, $apply] = [json_decode((string) file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR), in_array('--apply', $argv, true)];

$kernel = KernelFactory::create(
    environment: 'dev',
    debug: false,
    classLoader: $classLoader,
    pluginLoader: new DbalKernelPluginLoader($classLoader, null, Kernel::getConnection()),
);
$kernel->boot();
$container = $kernel->getContainer();
$context = Context::createDefaultContext();
$products = $container->get('product.repository');

$idsByNumber = static function (array $numbers) use ($products, $context): array {
    if ($numbers === []) {
        return [];
    }
    $criteria = (new Criteria())->addFilter(new EqualsAnyFilter('productNumber', array_values($numbers)));
    $out = [];
    foreach ($products->search($criteria, $context)->getEntities() as $p) {
        $out[$p->getProductNumber()] = ['id' => $p->getId(), 'parentId' => $p->getParentId(), 'children' => $p->getChildCount()];
    }

    return $out;
};

$parents = $idsByNumber($plan['split'] ?? []);
$variants = $idsByNumber($plan['delete'] ?? []);
$missing = array_diff(array_merge($plan['split'] ?? [], $plan['delete'] ?? []), array_keys($parents + $variants));
foreach ($parents as $n => $p) {
    if ($p['parentId'] !== null || $p['children'] === 0) {
        fwrite(STDERR, "not a parent with variants: $n\n");
        exit(1);
    }
}
printf("parents to make simple: %d, former variants to delete: %d, not found: %s\n",
    count($parents), count($variants), $missing ? implode(' ', $missing) : 'none');
if (!$apply) {
    echo "dry run, nothing changed (add --apply)\n";
    exit(0);
}

$controller = $container->get(VariantBuilderController::class);
$res = json_decode((string) $controller->split(
    new Request([], ['parentIds' => array_column($parents, 'id'), 'deleteParent' => false]),
    $context,
)->getContent(), true);
printf("split: %d, skipped: %d\n", count($res['split'] ?? []), count($res['skipped'] ?? []));

if ($variants !== []) {
    $products->delete(array_map(static fn ($v) => ['id' => $v['id']], array_values($variants)), $context);
}
printf("deleted: %d\n", count($variants));
