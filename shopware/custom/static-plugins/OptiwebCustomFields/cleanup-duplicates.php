<?php

/**
 * Cleanup script to remove duplicate custom fields
 * Run this script if you encounter duplicate custom field errors
 */

require_once __DIR__ . '/../../../../vendor/autoload.php';

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Kernel;

$kernel = new Kernel('dev', true);
$kernel->boot();

$container = $kernel->getContainer();
$context = Context::createDefaultContext();

/** @var EntityRepository $customFieldRepository */
$customFieldRepository = $container->get('custom_field.repository');

// Find all custom fields with the old name 'notSellable'
$criteria = new Criteria();
$criteria->addFilter(new EqualsFilter('name', 'notSellable'));

$duplicateFields = $customFieldRepository->search($criteria, $context);

if ($duplicateFields->getTotal() > 0) {
    echo "Found " . $duplicateFields->getTotal() . " duplicate custom fields with name 'notSellable'\n";
    
    $idsToDelete = [];
    foreach ($duplicateFields as $field) {
        $idsToDelete[] = ['id' => $field->getId()];
        echo "Will delete custom field: " . $field->getId() . " (Set: " . $field->getCustomFieldSetId() . ")\n";
    }
    
    if (!empty($idsToDelete)) {
        $customFieldRepository->delete($idsToDelete, $context);
        echo "Deleted " . count($idsToDelete) . " duplicate custom fields\n";
    }
} else {
    echo "No duplicate custom fields found\n";
}

echo "Cleanup completed!\n";
