<?php

declare(strict_types=1);

namespace OptiwebCustomFields\Services;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationEntity;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CustomFieldsManager
{
    public static function create(array $customFieldsGroups, ContainerInterface $container, Context $context): void
    {
        // $context = Context::createDefaultContext();

        /** @var EntityRepository $customFieldSetRepository */
        $customFieldSetRepository = $container->get('custom_field_set.repository');
        /** @var EntityRepository $customFieldRepository */
        $customFieldRepository = $container->get('custom_field.repository');
        /** @var EntityRepository $customFieldRelationRepository */
        $customFieldRelationRepository = $container->get('custom_field_set_relation.repository');

        $existingFieldSets = $customFieldSetRepository->search(new Criteria(), $context)->getElements();
        $existingCustomFields = $customFieldRepository->search(new Criteria(), $context)->getElements();
        $existingCustomRelationships = $customFieldRelationRepository->search(new Criteria(), $context)->getElements();

        //loop trough existing fieldSets and check by name if any matches to "name" in $customFields and add id if they match
        /** @var CustomFieldSetEntity $existingFieldSet */
        foreach ($existingFieldSets as $existingFieldSet) {
            foreach ($customFieldsGroups as &$customFieldsGroup) {
                if ($existingFieldSet->getName() === $customFieldsGroup["name"]) {
                    $customFieldsGroup['id'] = $existingFieldSet->getId();
                }
            }
            unset($customFieldsGroup);
        }

        // Match existing custom fields by name within each group and add ID if they match
        /** @var CustomFieldEntity $existingCustomField */
        foreach ($existingCustomFields as $existingCustomField) {
            foreach ($customFieldsGroups as &$customFieldsGroup) {
                foreach ($customFieldsGroup['customFields'] as &$customField) {
                    if ($existingCustomField->getName() === $customField['name']) {
                        $customField['id'] = $existingCustomField->getId();
                    }
                }
                unset($customField);
            }
            unset($customFieldsGroup);
        }

        // Match existing field relationships by entity name and parent set ID, then add ID if they match
        /** @var CustomFieldSetRelationEntity $existingCustomRelation */
        foreach ($existingCustomRelationships as $existingCustomRelation) {
            foreach ($customFieldsGroups as &$customFieldsGroup) {
                foreach ($customFieldsGroup['relations'] as &$relation) {
                    if (
                        $existingCustomRelation->getEntityName() === $relation['entityName'] &&
                        $existingCustomRelation->getCustomFieldSetId() === $customFieldsGroup['id']
                    ) {
                        $relation['id'] = $existingCustomRelation->getId();
                    }
                }
                unset($relation);
            }
            unset($customFieldsGroup);
        }

        // Create custom field sets first.
        //
        // The `unset()` calls above are load-bearing: the loops that resolve existing
        // ids iterate `$customFieldsGroups` by reference, and a reference left bound to
        // the last group turns this by-value loop into a writer — every iteration
        // assigns its group into that slot, so the last group is processed once for
        // every group and never itself. That is how a newly added category field
        // silently failed to appear while the product one did.
        foreach ($customFieldsGroups as $customFieldsGroup) {
            $customFieldSetData = [
                'id' => $customFieldsGroup['id'] ?? null,
                'name' => $customFieldsGroup['name'],
                'config' => $customFieldsGroup['config'],
                'active' => true,
                'global' => false,
                'position' => 1,
            ];

            $customFieldSetRepository->upsert([$customFieldSetData], $context);

            // Get the created/updated custom field set ID
            $criteria = new Criteria();
            $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('name', $customFieldsGroup['name']));
            $customFieldSet = $customFieldSetRepository->search($criteria, $context)->first();
            
            if ($customFieldSet) {
                $customFieldSetId = $customFieldSet->getId();
                
                // Create custom fields
                if (!empty($customFieldsGroup['customFields'])) {
                    $customFieldData = [];
                    foreach ($customFieldsGroup['customFields'] as $customField) {
                        $customFieldData[] = [
                            'id' => $customField['id'] ?? null,
                            'name' => $customField['name'],
                            'type' => $customField['type'],
                            'config' => $customField['config'],
                            'active' => $customField['active'],
                            'customFieldSetId' => $customFieldSetId,
                        ];
                    }
                    $customFieldRepository->upsert($customFieldData, $context);
                }

                // Create relations
                if (!empty($customFieldsGroup['relations'])) {
                    $relationData = [];
                    foreach ($customFieldsGroup['relations'] as $relation) {
                        $relationData[] = [
                            'id' => $relation['id'] ?? null,
                            'customFieldSetId' => $customFieldSetId,
                            'entityName' => $relation['entityName'],
                        ];
                    }
                    $customFieldRelationRepository->upsert($relationData, $context);
                }
            }
        }
    }

    public static function remove(array $customFields, ContainerInterface $container, Context $context): void
    {
        // $context = Context::createDefaultContext();

        /** @var EntityRepository $customFieldSetRepository */
        $customFieldSetRepository = $container->get('custom_field_set.repository');

        $customFieldSetNames = array_map(function ($customFieldSetArray) {
            return $customFieldSetArray["name"];
        }, $customFields);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter("name", $customFieldSetNames));
        $customFieldSetResult = $customFieldSetRepository->search($criteria, $context);

        if ($customFieldSetResult->getTotal() === 0) return;

        $customFieldSetsToRemove = $customFieldSetResult->map(
            function (CustomFieldSetEntity $customFieldSet) {
                return ["id" => $customFieldSet->getId()];
            }
        );

        $customFieldSetRepository->delete(array_values($customFieldSetsToRemove), $context);
    }
}
